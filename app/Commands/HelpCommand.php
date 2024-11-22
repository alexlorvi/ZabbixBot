<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Actions;
use \Telegram\Bot\Commands\Command;
use ZabbixBot\Services\MsgService;

class HelpCommand extends Command {
    protected string $name = 'help';
    private MsgService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = MsgService::getInstance();
        $this->description = $this->msg->getNested('command.help.description');
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

        # Get all the registered commands.
        $commands = $this->getTelegram()->getCommands();

        $response = '';
        foreach ($commands as $name => $command) {
            $response .= sprintf('/%s - %s' . PHP_EOL, $name, $command->getDescription());
        }

        $this->replyWithMessage(['text' => $response]);
    }
}