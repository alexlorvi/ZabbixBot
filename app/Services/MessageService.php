<?php

namespace ZabbixBot\Services;

use Telegram\Bot\Api;

class MessageService {

    protected Api $telegram;
    public function __construct(Api $tgApi) {
        $this->telegram = $tgApi;
    }

    public function sendMessage($chatId,string $message,$options = []) {
        $preparedMessage = $this->checkMessage($message);
        if (is_array($preparedMessage)) {
            foreach($preparedMessage as $messageline) {
                $this->telegram->sendMessage([ 
                    'chat_id' => $chatId, 
                    'text' => $messageline,
                ]);    
            }
        } else {
            $this->telegram->sendMessage([ 
                'chat_id' => $chatId, 
                'text' => $preparedMessage,
            ]);
        }
    }

    private function checkMessage(string $message):string|array {
        if (mb_strlen($message,'UTF-8') > 4096) {
            return splitUnicodeString($message);
        } else {
            return $message;
        }
    }

}