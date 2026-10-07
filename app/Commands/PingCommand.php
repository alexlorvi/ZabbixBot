<?php

namespace ZabbixBot\Commands;

use Telegram\Bot\Actions;
use Telegram\Bot\Api;
use Telegram\Bot\Commands\Command as TgCommand;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\Services\PingService;
use ZabbixBot\Services\LangService;
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
            $this->run($this->getTelegram(), $chatId, $host, PingService::clampCount($this->argument('count', PingService::DEFAULT_COUNT)));
        }
    }

    /** Пінг з живим оновленням одного повідомлення. Спільне для /ping і кнопки Ping на картці хоста. */
    public function run(Api $telegram, $chatId, string $host, int $count = PingService::DEFAULT_COUNT): void {
        if (!PingService::validHost($host)) {
            $telegram->sendMessage(['chat_id' => $chatId, 'text' => sprintf($this->msg->getNested('command.ping.badHost'), $host)]);
            return;
        }
        $message = $telegram->sendMessage(['chat_id' => $chatId, 'text' => $this->msg->getNested('command.ping.start')]);
        $messageId = $message->getMessageId();

        userLOG($chatId,'info',"< Ping command for host: $host");

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
    }
}
