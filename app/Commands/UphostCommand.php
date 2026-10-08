<?php

namespace ZabbixBot\Commands;

use Telegram\Bot\Commands\Command;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\PingService;
use ZabbixBot\Services\UphostJobs;
use ZabbixBot\Services\UsagePrompts;

/**
 * /uphost <host> [хвилин] - чекати, поки хост почне відповідати на ping: фоновий app:uphost-job пінгує його
 * кожні net.uphost_interval с і відповідає на команду, коли хост піднявся або сплив час.
 * Кілька завдань на чат (по одному на хост, UphostJobs), кожне - зі своєю кнопкою "Скасувати".
 */
class UphostCommand extends Command {
    protected string $name = 'uphost';
    protected string $description;
    protected string $pattern = '{host} {minutes: \d+}';
    private LangService $msg;

    public const DEFAULT_MINUTES = 30;
    public const MAX_MINUTES = 720;
    public const INTERVAL = 15;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle() {
        $host = trim((string)$this->argument('host', ''));
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $messenger = new MessageService($this->getTelegram());
        $userMessageId = (int)$this->getUpdate()->getMessage()->get('message_id');
        if ($host === '') {
            $usageId = $messenger->sendMessage($chatId, $this->t('usage'));
            if ($usageId !== null) {
                // якщо користувач відредагує команду - довідку буде видалено, а команду виконано (BotController)
                UsagePrompts::forBot()->remember((string)$chatId, $userMessageId, $usageId);
            }
            return;
        }
        $this->run($messenger, $chatId, $host, (int)$this->argument('minutes', 0), $userMessageId ?: null);
    }

    /** Хвилини очікування в межах 1..net.uphost_max_minutes; 0/порожньо - net.uphost_minutes. */
    public static function clampMinutes(int $minutes): int {
        $config = ConfigService::getInstance();
        if ($minutes < 1) {
            $minutes = (int)$config->getNested('net.uphost_minutes', self::DEFAULT_MINUTES);
        }
        return max(1, min($minutes, (int)$config->getNested('net.uphost_max_minutes', self::MAX_MINUTES)));
    }

    /**
     * Для /uphost, кнопки "⏳" картки хоста і "Чекати ще".
     * @param int|null $replyTo на яке повідомлення відповідати результатом
     */
    public function run(MessageService $messenger, $chatId, string $host, int $minutes = 0, ?int $replyTo = null): void {
        if (!PingService::validHost($host)) {
            $messenger->sendMessage($chatId, sprintf($this->msg->getNested('command.ping.badHost'), $host));
            return;
        }
        $minutes = self::clampMinutes($minutes);
        $reply = $replyTo !== null ? ['reply_parameters' => json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true])] : [];

        // уже живий - нема на що чекати
        if (PingService::alive($host)) {
            $messenger->sendMessage($chatId, sprintf($this->t('alreadyUp'), $host), $reply);
            return;
        }

        $cache = new FileCache(CACHE_PATH);
        $key = UphostJobs::key($host);
        // слот з запасом на хвилину; знімається завершеним job-ом
        $slot = UphostJobs::tryAdd($cache, (string)$chatId, $host, $minutes * 60 + 60);
        if ($slot !== 'ok') {
            $messenger->sendMessage($chatId, $slot === 'busy' ? sprintf($this->t('busy'), $host) : sprintf($this->t('limit'), UphostJobs::MAX_JOBS), $reply);
            return;
        }
        $interval = max(5, (int)ConfigService::getInstance()->getNested('net.uphost_interval', self::INTERVAL));
        $cancel = json_encode(['inline_keyboard' => [[['text' => $this->msg->getNested('command.ping.cancelButton'), 'callback_data' => 'up:x:'.$key]]]], JSON_UNESCAPED_UNICODE);
        $noticeId = $messenger->sendMessage($chatId, sprintf($this->t('started'), $host, date('H:i', time() + $minutes * 60), $interval),
            ['reply_markup' => $cancel] + $reply);

        $php = PingCommand::phpCli();
        $cmd = implode(' ', array_map('escapeshellarg', array_merge(
            [$php, ROOT_PATH.'/console.php', 'app:uphost-job', (string)$chatId, $host, (string)$minutes,
                '--interval='.$interval, '--lang='.$this->msg->getLang()],
            $noticeId !== null ? ['--notice='.$noticeId] : [],
            $replyTo !== null ? ['--reply-to='.$replyTo] : [],
        )));
        $error = PingCommand::launch($cmd, $php, $pid);
        if ($error !== null) {
            UphostJobs::remove($cache, (string)$chatId, $key);
            if ($noticeId !== null) {
                $messenger->deleteMessage($chatId, $noticeId);
            }
            mainLOG('main','error','Uphost job not started: '.$error);
            userLOG($chatId,'error','Uphost job not started: '.$error);
            $messenger->sendMessage($chatId, $this->t('failed'));
            return;
        }
        UphostJobs::attach($cache, (string)$chatId, $key, (int)$pid, (int)$noticeId);
        userLOG($chatId,'info',"< Uphost started: $host, $minutes min, pid $pid");
    }

    /** Кнопка "Скасувати" (up:x:<key>): лише завдання свого чату; ключ шукається в слотах цього чату. */
    public function cancel(MessageService $messenger, $chatId, string $key, ?int $messageId): void {
        $cache = new FileCache(CACHE_PATH);
        $job = UphostJobs::get($cache, (string)$chatId, $key);
        $pid = (int)($job['pid'] ?? 0);
        if ($job === null || !PingService::isOurJob($pid, (string)$chatId, 'app:uphost-job', (string)$job['host'])) {
            if ($job !== null) {
                UphostJobs::remove($cache, (string)$chatId, $key);
            }
            $text = $this->t('alreadyDone');
        } else {
            if (!PingService::killJob($pid)) {
                mainLOG('main','error','Uphost job not killed, pid '.$pid);
                return; // процес живий - слот і кнопка лишаються
            }
            UphostJobs::remove($cache, (string)$chatId, $key);
            userLOG($chatId,'info','Uphost cancelled: '.$job['host'].', pid '.$pid);
            $text = sprintf($this->t('cancelled'), $job['host']);
        }
        if ($messageId !== null) {
            $messenger->editMessage($chatId, $messageId, $text);
        }
    }

    private function t(string $key): string {
        return (string)$this->msg->getNested('command.uphost.'.$key);
    }
}
