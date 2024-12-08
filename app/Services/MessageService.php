<?php

namespace ZabbixBot\Services;

use Telegram\Bot\Api;
use Telegram\Bot\Actions;
use Telegram\Bot\Keyboard\Keyboard;
use ZabbixBot\Services\MessageQueue;

class MessageService {

    protected Api $telegram;
    protected MessageQueue $messageQueue;

    public function __construct(Api $tgApi) {
        $this->telegram = $tgApi;
        $this->messageQueue = new MessageQueue();
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
                try {
                    $this->telegram->sendMessage($sendArray);
                    userLOG($chatId,'info','< '.$messageline);
                } catch (\Exception $e) { 
                    // If there's an error, enqueue the message
                    userLOG($chatId,'error','Send Error - '.$e->getMessage().PHP_EOL.'Enqueue it.');
                    $this->messageQueue->enqueue($sendArray); 
                }
            }
        } else {
            $sendArray = $this->prepareParams(array_merge([ 
                'chat_id' => $chatId, 
                'text' => $preparedMessage,
            ],$options));
            try {
                $this->telegram->sendMessage($sendArray);    
                userLOG($chatId,'info','< '.$preparedMessage);
            } catch (\Exception $e) { 
                // If there's an error, enqueue the message
                userLOG($chatId,'error','Send Error - '.$e->getMessage().PHP_EOL.'Enqueue it.');
                $this->messageQueue->enqueue($sendArray); 
            }
        }
    }

    // CopyPaste from Copilot. Edit before use
    public function retryMessages() {
        while ($this->messageQueue->getQueueSize() > 0) {
            $message = $this->messageQueue->dequeue();
            try {
                $this->sendMessage($message['chat_id'], $message['text']);
            } catch (\Exception $e) {
                // Re-enqueue the message if it fails again
                $this->messageQueue->enqueue($message);
                break;
                // Stop retrying if the proxy is still down
            }
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