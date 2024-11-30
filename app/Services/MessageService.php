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
                $sendArray = $this->prepareParams(array_merge([ 
                    'chat_id' => $chatId, 
                    'text' => $messageline,
                ],$options));
                $this->telegram->sendMessage($sendArray);    
            }
        } else {
            $sendArray = $this->prepareParams(array_merge([ 
                'chat_id' => $chatId, 
                'text' => $preparedMessage,
            ],$options));
            $this->telegram->sendMessage($sendArray);    
        }
    }

    private function checkMessage(string $message):string|array {
        if (mb_strlen($message,'UTF-8') > 4096) {
            return splitUnicodeString($message);
        } else {
            return $message;
        }
    }

    private function prepareParams(array $params):array {
        $defaultParams = [
            'reply_markup' => $this->telegram->replyKeyboardMarkup(['remove_keyboard' => true,'selective' => false]),
        ];
        $compare = array_diff($defaultParams,$params);
        return array_merge($compare,$params);
    }

}