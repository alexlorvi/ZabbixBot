<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\NetTools;

class ApcCommand extends Command {
    protected string $name = 'apc';
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
                userLOG($message->getChat()->getId(),'info','< Command APC Usage reply');
            } catch (TelegramOtherException $e) {
                mainLOG('main','error',"Telegram Error: " . $e->getMessage());
            } catch (\Exception $e) {
                mainLOG('main','error',"General Error: " . $e->getMessage());
            }
            return;
        }

        $this->run($messenger, $chatId, $ip);
    }

    /** Спільне для /apc і кнопки APC на картці хоста. */
    public function run(MessageService $messenger, $chatId, string $ip): void {
        userLOG($chatId,'info',"< APC command for host: $ip");
        $messenger->sendMessage($chatId, NetTools::fromConfig()->apc($ip), ['parse_mode' => 'html']);
    }
}
