<?php

namespace ZabbixBot;

use Telegram\Bot\Api;

use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\ZabbixService;
use ZabbixBot\Services\LangService;
use ZabbixBot\UserController;
use ZabbixBot\CustomHttpClient;

/**
 * Class BotController.
 *
 */

class BotController {
    protected static Api $tgBot;
    protected array $config;
    protected UserController $user;
    protected ZabbixService $zabbixService;
    public function __construct(){
        $this->config = ConfigService::getInstance()->getNested('telegram');
        //$this->zabbixService = new ZabbixService();
        $this->user = new UserController();
        $this->tgBot = new Api($this->config['bot_token']);
        if (isset($this->config['proxy'])) {
            $httpClient = new CustomHttpClient();
            $httpClient->setProxy($this->config['proxy']);
            $this->tgBot->setHttpClientHandler($httpClient);
        }
        if (isset($this->config['commands']) && is_array($this->config['commands'])) {
            $this->tgBot->addCommands($this->config['commands']);
        }
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
        /* switch ($text) { 
            case '/test': 
                $responseText = 'Welcome to Test bot!'; 
                break; 
            default: 
                $responseText = 'You said: ' . $text; 
                break; 
        }  */

        if ($this->user->isUser() &&
            isset($this->config['user_commands']) && 
            is_array($this->config['user_commands'])) {
            
            $this->tgBot->addCommands($this->config['user_commands']);
            $msg = LangService::getInstance();
            $msg->setLang('ua');
        }

        if (!startsWith($text, '/')) { 
            $reply = ($this->user->isUser()) ? 'Zabbix User said: ':'You said: ';
            $reply .= $text;
            $this->tgBot->sendMessage([ 
                'chat_id' => $chatId, 
                'text' => $reply,
                'parse_mode' => 'markdown',
            ]); 
            userLOG($chatId,'info','< '.$reply);
        } elseif (is_array($this->tgBot->getCommands()) && count($this->tgBot->getCommands())>1) {
            $this->tgBot->commandsHandler(true);
        }
    }
}