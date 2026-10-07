<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Actions;
use \Telegram\Bot\Commands\Command;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\UserController;

class HelpCommand extends Command {
    protected string $name = 'help';
    private LangService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle()
    {
        $username = $this->getUpdate()->getMessage()->from->username;

        $this->replyWithMessage([
            'text' => sprintf($this->msg->getNested('command.help.message'),$username),
            'parse_mode' => 'markdown',
        ]);

        # This will update the chat status to "typing..."
        $this->replyWithChatAction(['action' => Actions::TYPING]);

        # Get all the registered commands (admin-only ones are hidden from non-admins).
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $user = new UserController(new MessageService($this->getTelegram()), $chatId);

        $this->replyWithMessage(['text' => $user->commandListText((array)$this->getTelegram()->getCommands())]);
    }
}
