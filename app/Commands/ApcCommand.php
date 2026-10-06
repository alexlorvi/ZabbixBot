<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\NetTools;

class ApcCommand extends Command {
    protected string $name = 'apc';
    protected string $pattern = '{ip}';
    private LangService $msg;
    protected string $description;
    private NetTools $net;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
        $cfg = ConfigService::getInstance()->getNested('net', []);
        $this->net = new NetTools($cfg, COMMANDS_PATH.'/get_Int_status_cisco2.sh');
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

        userLOG($chatId,'info',"< APC command for host: $ip");
        $messenger->sendMessage($chatId, $this->net->apc($ip), ['parse_mode' => 'html']);
    }
}
