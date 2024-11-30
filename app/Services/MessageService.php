<?php

namespace ZabbixBot\Services;

use Telegram\Bot\Api;
use Telegram\Bot\Actions;
use Telegram\Bot\Keyboard\Keyboard;

class MessageService {

    protected Api $telegram;
    public function __construct(Api $tgApi) {
        $this->telegram = $tgApi;
    }

    public function chatActionTyping( $chatID) {
        $this->telegram->sendChatAction(['chat_id'=>$chatID,'action' => Actions::TYPING]);
    }

    public function sendMessage($chatId,string $message,$options = []) {
        $this->chatActionTyping($chatId);
        $preparedMessage = $this->checkMessage($message);
        if (is_array($preparedMessage)) {
            foreach($preparedMessage as $messageline) {
                $sendArray = $this->prepareParams(array_merge([ 
                    'chat_id' => $chatId, 
                    'text' => $messageline,
                ],$options));
                $this->telegram->sendMessage($sendArray);
                userLOG($chatId,'info','< '.$messageline);
            }
        } else {
            $sendArray = $this->prepareParams(array_merge([ 
                'chat_id' => $chatId, 
                'text' => $preparedMessage,
            ],$options));
            $this->telegram->sendMessage($sendArray);    
            userLOG($chatId,'info','< '.$preparedMessage);
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
        $reply_markup = Keyboard::remove(['selective' => false]);
        $defaultParams = [
            'reply_markup' => $reply_markup,
        ];
        $compare = array_diff($defaultParams,$params);
        return array_merge($compare,$params);
    }

}