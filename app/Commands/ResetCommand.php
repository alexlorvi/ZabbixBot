<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\UserController;

class ResetCommand extends Command {
    protected string $name = 'reset';
    private LangService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle() {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $messenger = new MessageService($this->getTelegram());

        $user = new UserController($messenger, $chatId);
        $messenger->sendMessage($chatId, $this->msg->getNested($user->resetCache() ? 'command.reset.done' : 'command.reset.denied'));
    }
}
