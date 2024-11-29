<?php

namespace ZabbixBot\Services;

use Telegram\Bot\Api;

class MessageService {

    protected Api $telegram;
    public function __construct(Api $tgApi) {
        $this->telegram = $tgApi;
    }

    public function sendMessage($chatId, $message) {
        $this->telegram->sendMessage([ 
            'chat_id' => $chatId, 
            'text' => $message,
        ]); 
    }

}