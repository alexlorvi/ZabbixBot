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
        $notice = $telegram->sendMessage(['chat_id' => $chatId, 'text' => sprintf($this->msg->getNested('command.ping.bulkStarted'), $host, $count)] + self::replyParams($replyTo));

        $php = (string)ConfigService::getInstance()->getNested('net.php_cli', PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR.'/php');
        $cmd = implode(' ', array_map('escapeshellarg', array_merge(
            [$php, ROOT_PATH.'/console.php', 'app:ping-job', (string)$chatId, $host, (string)$count,
                '--lang='.$this->msg->getLang(), '--notice='.(int)$notice->getMessageId()],
            $replyTo !== null ? ['--reply-to='.$replyTo] : [],
        )));
        exec('nohup '.$cmd.' > /dev/null 2>&1 &', $out, $rc);
        userLOG($chatId,'info',"< Background ping for host: $host, count $count (rc $rc)");
        if ($rc !== 0) {
            PingService::unlock($cache, (string)$chatId);
            $telegram->sendMessage(['chat_id' => $chatId, 'text' => $this->msg->getNested('command.ping.bulkFailed')]);
        }
    }

    private static function replyParams(?int $replyTo): array {
        return $replyTo !== null
            ? ['reply_parameters' => json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true])]
            : [];
    }
}
