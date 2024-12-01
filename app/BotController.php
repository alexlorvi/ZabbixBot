<?php

namespace ZabbixBot;

use Telegram\Bot\Api;

use ZabbixBot\Services\ConfigService;
use ZabbixBot\UserController;
use ZabbixBot\CustomHttpClient;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\LangService;
use DateTime;

/**
 * Class BotController.
 *
 */

class BotController {
    protected Api $tgBot;
    protected array $config;
    protected UserController $user;
    protected MessageService $message;
    protected LangService $msg;
    public function __construct(){
        $this->config = ConfigService::getInstance()->getNested('telegram');

        $this->msg = LangService::getInstance();

        $this->tgBot = new Api($this->config['bot_token']);
        if (isset($this->config['proxy'])) {
            $httpClient = new CustomHttpClient();
            $httpClient->setProxy($this->config['proxy']);
            $this->tgBot->setHttpClientHandler($httpClient);
        }

        if (isset($this->config['commands']) && is_array($this->config['commands'])) {
            $this->tgBot->addCommands($this->config['commands']);
        }

        $this->message = new MessageService($this->tgBot);
        $this->user = new UserController($this->message);
    }

    public function registerHook():string {
        $responce = $this->tgBot->setWebhook($this->config['webhook_url']) ? 'SUCCESS':'ERROR';
        return $responce . ': WebHook -> '. $this->config['webhook_url'];
    }

    public function handleWebhook():void {
        $updates = $this->tgBot->getWebhookUpdate(); 
        /**
         * Types:
         * 'message',
         * 'edited_message',
         * 'channel_post',
         * 'edited_channel_post',
         * 'inline_query',
         * 'chosen_inline_result',
         * 'callback_query',
         * 'shipping_query',
         * 'pre_checkout_query',
         * 'poll',
         * 'poll_answer',
         * 'my_chat_member',
         * 'chat_member',
         * 'chat_join_request',
         */
        if ($updates->isType('message')) { 
            $this->handleMessage($updates->getMessage()); 
        } else {
            mainLOG('main','info','Get message - '.$updates->objectType());
            mainLOG('main','debug',print_r($updates));
        };
    }
    public function handleMessage($message) { 
        $chatId = $message->getChat()->getId(); 
        $text = $message->getText(); 
        $this->user->setUserID($chatId);
        userLOG($chatId,'info','> '.$text);
 
        if ($this->user->isUser() &&
            isset($this->config['user_commands']) && 
            is_array($this->config['user_commands'])) {
            
            $this->tgBot->addCommands($this->config['user_commands']);
        }

        // Registered User Area
        if ($this->user->isUser()) {
            switch (true) {
                case (preg_match('/^\/ev([0-9])+$/i', $text)):
                    // Command in format /ev{\d+}
                    $this->user->displayEventById(substr($text, 3));
                    break;
                
                case (preg_match('/^\/([0-9])+sec$/i', $text)):
                    $sec = substr($text,strlen('/'),strlen($text)-(strlen('sec')+1));
                    $dtF = new DateTime('@0');
                    $dtT = new DateTime("@$sec");
                    $reply = $dtF->diff($dtT)->format($this->msg->getNested('main.dateSec'));
                    $this->message->sendMessage($chatId,$reply);
                    break;

                case (preg_match('/^\/([0-9])+h$/i', $text)):
                    $time = strtotime('-'.substr($text,1,strlen($text)-2).' hour', time());
                    $this->user->displayUserEventsFull(null,null,$time);
                    break;

                case ($text == '123'):
                    $this->user->displayUserEventsSummary();
                    break;

                case ($text == '321'):
                    $this->user->displayUserEventsFull();
                    break;
    
                case (!str_starts_with($text, '/')):
                    break;

                case (str_starts_with($text, '/')):
                    //other commands to the default CommandsHandler
                    if (is_array($this->tgBot->getCommands()) && 
                        count($this->tgBot->getCommands())>1) {
                        $this->tgBot->commandsHandler(true);
                    };
                    break;
            }
        } 
        // Guest Area
        elseif (!str_starts_with($text, '/')) { 
            // ignore?
        } elseif (is_array($this->tgBot->getCommands()) && count($this->tgBot->getCommands())>1) {
            $this->tgBot->commandsHandler(true);
        }

    }
}