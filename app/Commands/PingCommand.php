<?php

namespace ZabbixBot\Commands;

use Telegram\Bot\Actions;
use Telegram\Bot\Api;
use Telegram\Bot\Commands\Command as TgCommand;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\PingService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\Router;
use ZabbixBot\Services\UsagePrompts;

class PingCommand extends TgCommand {
    protected string $name = 'ping';
    protected string $description;
    protected string $pattern = '{host} {count: \d+}';
    private LangService $msg;
    protected PingService $pingService;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
        $this->pingService = new PingService();
    }
    public function handle()
    {
        $host = trim((string)$this->argument('host', ''));
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();

        if ($host === '') {
            try {
                $reply = $this->msg->getNested('command.'.$this->name.'.usage');
                $message = $this->replyWithMessage([
                    'text' => $reply,
                    'parse_mode' => 'markdown',
                ]);
                userLOG($message->getChat()->getId(),'info','< Command Ping Usage reply');
                // якщо користувач відредагує команду - довідку буде видалено, а команду виконано (BotController)
                UsagePrompts::forBot()->remember((string)$message->getChat()->getId(), (int)$this->getUpdate()->getMessage()->get('message_id'), (int)$message->getMessageId());
            } catch (TelegramOtherException $e) {
                mainLOG('main','error',"Telegram Error: " . $e->getMessage());
            } catch (\Exception $e) {
                mainLOG('main','error',"General Error: " . $e->getMessage());
            }
        } else {
            $this->run($this->getTelegram(), $chatId, $host, (int)$this->argument('count', PingService::DEFAULT_COUNT),
                (int)$this->getUpdate()->getMessage()->get('message_id') ?: null);
        }
    }

    /** Найбільша дозволена кількість пакетів (net.ping_max_count). */
    public static function maxCount(): int {
        return (int)ConfigService::getInstance()->getNested('net.ping_max_count', PingService::MAX_COUNT);
    }

    /**
     * Пінг для /ping, кнопки Ping картки хоста і кнопки "Повторити".
     * До PingService::LIVE_MAX пакетів - живий вивід одним повідомленням, понад - фоновий app:ping-job з підсумком.
     * @param int|null $replyTo повідомлення, на яке відповідати (команда користувача або результат з кнопкою)
     */
    public function run(Api $telegram, $chatId, string $host, int $count = PingService::DEFAULT_COUNT, ?int $replyTo = null): void {
        if (!PingService::validHost($host)) {
            $telegram->sendMessage(['chat_id' => $chatId, 'text' => sprintf($this->msg->getNested('command.ping.badHost'), $host)]);
            return;
        }
        $count = PingService::clampCount($count, self::maxCount());
        if (!PingService::isLive($count)) {
            $this->startBackground($telegram, $chatId, $host, $count, $replyTo);
            return;
        }

        $message = $telegram->sendMessage(['chat_id' => $chatId, 'text' => $this->msg->getNested('command.ping.start')] + self::replyParams($replyTo));
        $messageId = $message->getMessageId();

        userLOG($chatId,'info',"< Ping command for host: $host, count $count");

        try {
            $telegram->sendChatAction(['chat_id' => $chatId, 'action' => Actions::TYPING]);
        } catch (\Exception $e) {
            // лише косметика
        }

        $pingResults = $this->msg->getNested('command.ping.start');
        $callback = function ($line) use (&$pingResults, $telegram, $chatId, $messageId) {
            $pingResults .= $line;
            try {
                userLOG($chatId,'debug',"<<<<".$pingResults);
                $telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $pingResults
                ]);
            } catch (TelegramOtherException $e) {
                mainLOG('main','error',"Telegram Error: " . $e->getMessage());
            } catch (\Exception $e) {
                mainLOG('main','error',"General Error: " . $e->getMessage());
            }
        };
        $this->pingService->ping($host,$count,$callback);

        // по завершенні - кнопка "Повторити" під результатом
        $markup = Router::repeatMarkup('ping', $host, $count === PingService::DEFAULT_COUNT ? null : $count, $this->msg->getNested('net.repeat'));
        if ($markup !== null) {
            try {
                $telegram->editMessageReplyMarkup(['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => $markup]);
            } catch (\Exception $e) {
                mainLOG('main','error',"Telegram Error: " . $e->getMessage());
            }
        }
    }

    /** Довгий пінг: повідомлення "взято в роботу" і фоновий app:ping-job (один на чат), підсумок прийде відповіддю. */
    private function startBackground(Api $telegram, $chatId, string $host, int $count, ?int $replyTo): void {
        $cache = new FileCache(CACHE_PATH);
        // слот з запасом: ~1с на пакет + хвилина; знімається завершеним job-ом
        if (!PingService::tryLock($cache, (string)$chatId, $count + 120)) {
            $telegram->sendMessage(['chat_id' => $chatId, 'text' => $this->msg->getNested('command.ping.bulkBusy')] + self::replyParams($replyTo));
            return;
        }
        // кнопка без параметрів: скасовується завдання свого чату (PID - у слоті, не в callback_data)
        $cancel = json_encode(['inline_keyboard' => [[['text' => $this->msg->getNested('command.ping.cancelButton'), 'callback_data' => 'net:cancel']]]], JSON_UNESCAPED_UNICODE);
        try {
            $notice = $telegram->sendMessage(['chat_id' => $chatId, 'text' => sprintf($this->msg->getNested('command.ping.bulkStarted'), $host, $count), 'reply_markup' => $cancel] + self::replyParams($replyTo));
        } catch (\Exception $e) {
            // без "взято в роботу" не запускаємо, а слот звільняємо - інакше чат до count+120 с отримував би "зайнято"
            PingService::unlock($cache, (string)$chatId);
            mainLOG('main','error','Background ping notice not sent: '.$e->getMessage());
            return;
        }

        $php = self::phpCli();
        $cmd = implode(' ', array_map('escapeshellarg', array_merge(
            [$php, ROOT_PATH.'/console.php', 'app:ping-job', (string)$chatId, $host, (string)$count,
                '--lang='.$this->msg->getLang(), '--notice='.(int)$notice->getMessageId()],
            $replyTo !== null ? ['--reply-to='.$replyTo] : [],
        )));
        $error = self::launch($cmd, $php, $pid);
        if ($error !== null) {
            PingService::unlock($cache, (string)$chatId);
            try {
                $telegram->deleteMessage(['chat_id' => $chatId, 'message_id' => $notice->getMessageId()]);
            } catch (\Exception $e) {
            }
            mainLOG('main','error','Background ping not started: '.$error);
            userLOG($chatId,'error','Background ping not started: '.$error);
            $telegram->sendMessage(['chat_id' => $chatId, 'text' => $this->msg->getNested('command.ping.bulkFailed')]);
            return;
        }
        PingService::attachJob($cache, (string)$chatId, $pid, (int)$notice->getMessageId(), $host);
        userLOG($chatId,'info',"< Background ping for host: $host, count $count, pid $pid");
    }

    /**
     * Кнопка "Скасувати" під "взято в роботу": зупиняє фонове завдання свого чату (разом з ping),
     * звільняє слот і замінює повідомлення на "скасовано".
     */
    public function cancel(Api $telegram, $chatId, ?int $messageId): void {
        $cache = new FileCache(CACHE_PATH);
        $job = PingService::job($cache, (string)$chatId);
        $pid = (int)($job['pid'] ?? 0);
        if ($job === null || !PingService::isOurJob($pid, (string)$chatId)) {
            // вже завершився (або слот завис) - просто прибираємо кнопку
            if ($job !== null) {
                PingService::unlock($cache, (string)$chatId);
            }
            $text = $this->msg->getNested('command.ping.alreadyDone');
        } else {
            if (!PingService::killJob($pid)) {
                // процес живий і слот лишається за ним - кнопка лишається, можна натиснути ще раз
                mainLOG('main','error','Background ping not killed, pid '.$pid);
                return;
            }
            PingService::unlock($cache, (string)$chatId);
            userLOG($chatId,'info','Background ping cancelled, pid '.$pid);
            $text = sprintf($this->msg->getNested('command.ping.cancelled'), (string)($job['host'] ?? ''));
        }
        if ($messageId !== null) {
            try {
                $telegram->editMessageText(['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text]);
            } catch (\Exception $e) {
                mainLOG('main','error','Telegram Error: '.$e->getMessage());
            }
        }
    }

    /** PHP CLI для фонових завдань: net.php_cli, інакше PHP_BINDIR/php (вебхук працює під php-fpm, PHP_BINARY - не CLI). */
    public static function phpCli(): string {
        return (string)ConfigService::getInstance()->getNested('net.php_cli', PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR.'/php');
    }

    /** Файл з виводом фонових завдань (помилки запуску PHP, винятки). */
    private const JOB_LOG_MAX = 5 * 1024 * 1024;

    public static function jobLog(): string {
        return rtrim((string)ConfigService::getInstance()->getNested('logger.file_path', LOG_PATH), '/').'/ping-job.log';
    }

    /**
     * Запускає команду у фоні і перевіряє, що процес справді живий.
     * Через setsid: PID = група процесів, тож "Скасувати" зупиняє і php, і дочірній ping.
     * @param int $pid out: PID запущеного процесу
     * @return string|null null - запущено, інакше причина
     */
    public static function launch(string $cmd, string $php, &$pid = 0): ?string {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            return 'exec() is disabled (disable_functions)';
        }
        if (!is_executable($php)) {
            return "PHP CLI not found or not executable: $php (set net.php_cli)";
        }
        $log = self::jobLog();
        clearstatcache();
        // лог фонових завдань не росте безмежно: понад JOB_LOG_MAX - у .1 (одна попередня копія)
        if ((int)@filesize($log) > self::JOB_LOG_MAX) {
            @rename($log, $log.'.1');
            clearstatcache();
        }
        $before = (int)@filesize($log);
        // вивід - у лог (а не /dev/null), $! - PID фонового процесу
        exec('setsid nohup '.$cmd.' >> '.escapeshellarg($log).' 2>&1 & echo $!', $out, $rc);
        $pid = (int)($out[0] ?? 0);
        if ($rc !== 0 || $pid <= 0) {
            return "launch failed (rc $rc)";
        }
        usleep(500000);
        if (!file_exists('/proc/'.$pid)) {
            // процес уже завершився - або миттєва помилка, або дуже швидкий пінг; причина - в кінці логу
            clearstatcache();
            $tail = trim((string)@file_get_contents($log, false, null, $before));
            if ($tail !== '' && preg_match('/error|exception|not found|denied|fatal/i', $tail)) {
                return "process $pid exited: $tail";
            }
        }
        return null;
    }

    private static function replyParams(?int $replyTo): array {
        return $replyTo !== null
            ? ['reply_parameters' => json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true])]
            : [];
    }
}
