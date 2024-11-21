<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Actions;
use \Telegram\Bot\Commands\Command;

class HelpCommand extends Command {
    protected string $name = 'help';
    protected string $description = 'Help Command to describe Bot commands';

    public function handle()
    {
        $username = $this->getUpdate()->getMessage()->from->username;

        $this->replyWithMessage([
            'text' => "Hello {$username}! Welcome to our bot, Here are our available commands:"
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