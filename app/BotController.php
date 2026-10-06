<?php

namespace ZabbixBot;

use Telegram\Bot\Api;

use ZabbixBot\Services\ConfigService;
use ZabbixBot\UserController;
use ZabbixBot\CustomHttpClient;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\RateLimiter;
use ZabbixBot\Services\UpdateDeduplicator;
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
    protected RateLimiter $limiter;
    protected UpdateDeduplicator $dedupe;
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

        $cache = new FileCache(CACHE_PATH);
        $this->limiter = new RateLimiter($cache);
        $this->dedupe = new UpdateDeduplicator($cache);
    }

    public function registerHook():string {
        $params = [
            'url' => $this->config['webhook_url'],
            'allowed_updates' => ['message', 'callback_query'],
        ];
        if (!empty($this->config['webhook_secret'])) {
            $params['secret_token'] = $this->config['webhook_secret'];
        }
        $responce = $this->tgBot->setWebhook($params) ? 'SUCCESS':'ERROR';
        return $responce . ': WebHook -> '. $this->config['webhook_url'];
    }

    public function handleWebhook():void {
        $secret = (string)($this->config['webhook_secret'] ?? '');
        if ($secret !== '' && !hash_equals($secret, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
            mainLOG('main','warning','Webhook secret mismatch from '.($_SERVER['REMOTE_ADDR'] ?? '?'));
            http_response_code(403);
            return;
        }

        $updates = $this->tgBot->getWebhookUpdate();

        if ($this->dedupe->seen((int)$updates->getUpdateId())) {
            mainLOG('main','info','Duplicate update '.$updates->getUpdateId());
            return;
        }
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
            $message = $updates->getMessage();
            $this->handleMessage($message->getChat()->getId(), $message->getText());
        } elseif ($updates->isType('callback_query')) {
            $callback = $updates->getCallbackQuery();
            $this->tgBot->answerCallbackQuery(['callback_query_id' => $callback->getId()]);
            $this->handleMessage($callback->getMessage()->getChat()->getId(), (string)$callback->getData());
        } else {
            mainLOG('main','info','Get message - '.$updates->objectType());
            mainLOG('main','debug',print_r($updates));
        };
    }
    public function handleMessage($chatId, $text) {
        $this->user->setUserID($chatId);
        userLOG($chatId,'info','> '.$text);

        [$max, $window] = $this->config['rate_limit'] ?? [30, 60];
        if (!$this->limiter->allow('cmd:'.$chatId, (int)$max, (int)$window)) {
            userLOG($chatId,'warning','Rate limit exceeded');
            if ($this->limiter->allow('cmd-notice:'.$chatId, 1, 60)) {
                $this->message->sendMessage($chatId,'Забагато запитів. Зачекайте хвилину.');
            }
            return;
        }

        if ($this->user->isUser() &&
            isset($this->config['user_commands']) && 
            is_array($this->config['user_commands'])) {
            
            $this->tgBot->addCommands($this->config['user_commands']);
        }

        // Registered User Area
        if ($this->user->isUser()) {
            $menu = $this->msg->getNested('command.menu.menuaction') ?? [] ;
            switch (true) {
                case (preg_match('/^\/ev([0-9])+$/i', $text)):
                    // Command in format /ev{\d+}
                    $this->user->displayEventById(substr($text, 3));
                    break;

                case (preg_match('/^\/hostid([0-9]+)$/i', $text)):
                    // Натискання inline-кнопки хоста зі списку результатів /host
                    $token = $this->user->getUserToken();
                    if ($token !== null) {
                        (new \ZabbixBot\Commands\HostCommand())->showHost($this->message, $chatId, $token, substr($text, strlen('/hostid')));
                    }
                    break;

                case (preg_match('/^\/([0-9])+sec$/i', $text)):
                    // Command in format /{\d+}sec
                    $sec = substr($text,strlen('/'),strlen($text)-(strlen('sec')+1));
                    $dtF = new DateTime('@0');
                    $dtT = new DateTime("@$sec");
                    $reply = $dtF->diff($dtT)->format($this->msg->getNested('main.dateSec'));
                    $this->message->sendMessage($chatId,$reply);
                    break;

                case (preg_match('/^\/([0-9])+h$/i', $text)):
                    // Command in format /{\d+}h. Old preset /24h /72h
                    $time = strtotime('-'.substr($text,1,strlen($text)-2).' hour', time());
                    $this->user->displayUserEventsFull(null,null,$time);
                    break;

                /* case ($text == '123'):
                    $this->user->displayUserEventsSummary();
                    break;

                case ($text == '321'):
                    $this->user->displayUserEventsFull();
                    break; */
                case (array_key_exists($text,$menu)):
                    $this->message->sendMessage($chatId,'Menu option');
                    $this->checkKeyboard($text);
                    break;
    
                case (!str_starts_with($text, '/')):
                    //another text formats
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

    private function checkKeyboard(string $text) {
        $menuActions = $this->msg->getNested('command.menu.menuaction');
        if (isset($menuActions[$text])) {
            $action = $menuActions[$text];
            //call_user_func_array([$controller, $action['method']], $action['params']);
            call_user_func_array([$this->user, $action['method']],$action['params']);
        }
    }
}