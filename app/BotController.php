<?php

namespace ZabbixBot;

use Telegram\Bot\Api;

use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\LoggerService as LOG;

/**
 * Class BotController.
 *
 */

class BotController {
    private Api $tgBot;
    protected array $config;
    public function __construct(){
        $this->config = ConfigService::getInstance()->getNested('telegram');
        LOG::getInstance();
        $this->tgBot = new Api($this->config['bot_token']);
        if (isset($this->config['commands']) && is_array($this->config['commands'])) {
            $this->tgBot->addCommands($this->config['commands']);
            $this->tgBot->commandsHandler(true);
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
            LOG::info('Get message - '.$updates->objectType().' '.$updates->getMessage()->getChat()->getId().' '.$updates->getMessage()->getText());
        } else {
            LOG::warning('Get message - '.$updates->objectType());
        };
    }
    public function handleMessage($message) { 
        $chatId = $message->getChat()->getId(); 
        $text = $message->getText(); 
        /* switch ($text) { 
            case '/test': 
                $responseText = 'Welcome to Test bot!'; 
                break; 
            default: 
                $responseText = 'You said: ' . $text; 
                break; 
        }  */
        if (!startsWith($text, '/')) { 
            $this->tgBot->sendMessage([ 
                'chat_id' => $chatId, 
                'text' => 'You said: ' . $text, 
            ]); 
        }
    }
}