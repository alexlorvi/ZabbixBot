<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\UserController;

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
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $messenger = new MessageService($this->getTelegram());
        $user = new UserController($messenger, $chatId);

        if ((string)$user->getPreference('menu_style', 'inline') === 'reply') {
            $this->sendReply($messenger, $chatId);
            return;
        }
        [$text, $keyboard] = $this->renderInline($user);
        $messenger->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    private function sendReply(MessageService $messenger, $chatId): void {
        $reply = $this->msg->getNested('command.'.$this->name.'.message');
        $menu = $this->msg->getNested('command.'.$this->name.'.menu');

        $reply_markup = Keyboard::make()->setResizeKeyboard(true)->setOneTimeKeyboard(true);
        foreach($menu as $row) {
            $reply_markup->row($row);
        }

        $messenger->sendMessage($chatId, $reply, ['reply_markup' => $reply_markup]);
    }

    /** @return array{0:string,1:Keyboard} */
    public function renderInline(UserController $user): array {
        $text = $this->msg->getNested('command.'.$this->name.'.message');

        $keyboard = Keyboard::make()->inline();
        $keyboard->row([Keyboard::inlineButton([
            'text' => $this->msg->getNested('command.menu.full_button'),
            'callback_data' => 'menu:full',
        ])]);
        $keyboard->row([Keyboard::inlineButton([
            'text' => $this->msg->getNested('command.menu.summary_button'),
            'callback_data' => 'menu:summary',
        ])]);
        $keyboard->row([Keyboard::inlineButton([
            'text' => $this->msg->getNested('command.menu.help_button'),
            'callback_data' => 'menu:help',
        ])]);
        $keyboard->row([Keyboard::inlineButton([
            'text' => $this->msg->getNested('command.menu.settings_button'),
            'callback_data' => 'set:open',
        ])]);

        return [$text, $keyboard];
    }

    /** Редагує вже надіслане повідомлення назад в inline-меню (виклик з "Назад" у налаштуваннях). */
    public function editOpenInline(MessageService $messenger, UserController $user, $chatId, $messageId): void {
        [$text, $keyboard] = $this->renderInline($user);
        $messenger->editMessage($chatId, $messageId, $text, $keyboard);
    }
}
