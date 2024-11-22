<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command as TgCommand;
use ZabbixBot\Services\MsgService;

class StartCommand extends TgCommand {
    protected string $name = 'start';
    private MsgService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = MsgService::getInstance();
        $this->description = $this->msg->getNested('command.start.description');
    }

    public function handle()
    {
        $username = $this->getUpdate()->getMessage()->from->username;
        $userId = $this->getUpdate()->getMessage()->from->id;

        $this->replyWithMessage([
            'text' => sprintf($this->msg->getNested('command.start.message'),$username,$userId),
            'parse_mode' => 'markdown',
        ]);
    }
}