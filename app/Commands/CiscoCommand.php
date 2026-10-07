<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\UsagePrompts;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\NetTools;

class CiscoCommand extends Command {
    protected string $name = 'cisco';
    protected string $pattern = '{ip}';
    private LangService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle() {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $messenger = new MessageService($this->getTelegram());
        $ip = trim((string)$this->argument('ip', ''));

        if ($ip === '') {
            try {
                $reply = $this->msg->getNested('command.'.$this->name.'.usage');
                $message = $this->replyWithMessage([
                    'text' => $reply,
                    'parse_mode' => 'markdown',
                ]);
                userLOG($message->getChat()->getId(),'info','< Command Cisco Usage reply');
                // якщо користувач відредагує команду - довідку буде видалено, а команду виконано (BotController)
                UsagePrompts::forBot()->remember((string)$message->getChat()->getId(), (int)$this->getUpdate()->getMessage()->get('message_id'), (int)$message->getMessageId());
            } catch (TelegramOtherException $e) {
                mainLOG('main','error',"Telegram Error: " . $e->getMessage());
            } catch (\Exception $e) {
                mainLOG('main','error',"General Error: " . $e->getMessage());
            }
            return;
        }

        $this->run($messenger, $chatId, $ip);
    }

    /** Спільне для /cisco і кнопки Cisco на картці хоста. */
    public function run(MessageService $messenger, $chatId, string $ip): void {
        $messenger->sendMessage($chatId, $this->msg->getNested('command.cisco.wait'));
        userLOG($chatId,'info',"< Cisco command for host: $ip");
        $messenger->sendMessage($chatId, NetTools::fromConfig()->cisco($ip));
    }
}
