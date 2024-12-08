<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Button;
use ZabbixBot\Services\LangService;
use Telegram\Bot\Keyboard\Keyboard;

class MenuCommand extends Command {
    protected string $name = 'menu';
    private LangService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle()
    {
        $reply = $this->msg->getNested('command.'.$this->name.'.message');
        $menu = $this->msg->getNested('command.'.$this->name.'.menu');

        $reply_markup = Keyboard::make()->setResizeKeyboard(true)->setOneTimeKeyboard(true);
        foreach($menu as $row) {
            $reply_markup->row($row);
        }

        $this->replyWithMessage([
            'text' => $reply,
            'reply_markup' => $reply_markup,
        ]);

    }
}