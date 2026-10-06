<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\ZabbixService;

class ResetCommand extends Command {
    protected string $name = 'reset';
    private LangService $msg;
    protected string $description;
    private ZabbixService $zbx;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
        $this->zbx = new ZabbixService();
    }

    public function handle() {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $messenger = new MessageService($this->getTelegram());

        if (!$this->zbx->isAdmin((string)$chatId)) {
            $messenger->sendMessage($chatId, 'Га?');
            return;
        }

        $this->zbx->resetUserCache();
        $messenger->sendMessage($chatId, 'Кеш користувачів і груп очищено');
    }
}
