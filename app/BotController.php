<?php

namespace ZabbixBot;

use Telegram\Bot\Api;

use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\RateLimiter;
use ZabbixBot\Services\UpdateDeduplicator;
use ZabbixBot\Services\UsagePrompts;
use ZabbixBot\Services\Router;
use ZabbixBot\Services\TelegramFactory;
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
    protected UsagePrompts $prompts;
    public function __construct(){
        $this->config = ConfigService::getInstance()->getNested('telegram');

        $this->msg = LangService::getInstance();

        $this->tgBot = TelegramFactory::make($this->config);

        if (isset($this->config['commands']) && is_array($this->config['commands'])) {
            $this->tgBot->addCommands($this->config['commands']);
        }

        $this->message = new MessageService($this->tgBot);
        $this->user = new UserController($this->message);

        $cache = new FileCache(CACHE_PATH);
        $this->limiter = new RateLimiter($cache);
        $this->dedupe = new UpdateDeduplicator($cache);
        $this->prompts = new UsagePrompts($cache);
    }

    public function registerHook():string {
        $params = [
            'url' => $this->config['webhook_url'],
            // edited_message - виправлена порожня команда (див. UsagePrompts)
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
        ];
        if (!empty($this->config['webhook_secret'])) {
            $params['secret_token'] = $this->config['webhook_secret'];
        }
        $responce = $this->tgBot->setWebhook($params) ? 'SUCCESS':'ERROR';
        return $responce . ': WebHook -> '. $this->config['webhook_url'];
    }

    public function getWebhookInfo(): array {
        return $this->tgBot->getWebhookInfo()->toArray();
    }

    public function deleteWebhook(): string {
        return $this->tgBot->deleteWebhook() ? 'SUCCESS: WebHook deleted' : 'ERROR';
    }

    public function handleWebhook():void {
        if (!Router::secretValid((string)($this->config['webhook_secret'] ?? ''), (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
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
            // Відповідь (reply) на повідомлення бота - можливо, коментар до події Zabbix
            $replyTo = $message->get('reply_to_message');
            $reply = $replyTo !== null && ($replyTo->get('from')?->get('is_bot') ?? false)
                ? ['message_id' => (int)$replyTo->get('message_id'), 'text' => (string)($replyTo->get('text') ?? $replyTo->get('caption') ?? ''), 'own_id' => (int)$message->get('message_id')]
                : null;
            $this->handleMessage($message->getChat()->getId(), (string)$message->getText(), null, $reply);
        } elseif ($updates->isType('edited_message')) {
            // Реагуємо лише на виправлення команди, на яку бот відповів довідкою: довідку прибираємо, команду виконуємо.
            // Інші редагування ігноруються, щоб правка старого повідомлення не перезапускала команди.
            $message = $updates->getMessage();
            $chatId = $message->getChat()->getId();
            $usageId = $this->prompts->take((string)$chatId, (int)$message->get('message_id'));
            if ($usageId === null) {
                return;
            }
            userLOG($chatId,'info','Edited command, usage message '.$usageId.' replaced');
            $this->message->deleteMessage($chatId, $usageId);
            $this->handleMessage($chatId, (string)$message->getText(), null, null);
        } elseif ($updates->isType('callback_query')) {
            $callback = $updates->getCallbackQuery();
            $this->tgBot->answerCallbackQuery(['callback_query_id' => $callback->getId()]);
            $this->handleMessage($callback->getMessage()->getChat()->getId(), (string)$callback->getData(), $callback->getMessage()->getMessageId());
        } else {
            mainLOG('main','info','Get message - '.$updates->objectType());
            mainLOG('main','debug',json_encode($updates->toArray(), JSON_UNESCAPED_UNICODE));
        };
    }
    /**
     * @param int|null $messageId повідомлення з натиснутою inline-кнопкою (callback_query), інакше null
     * @param array{message_id:int,text:string,own_id:int}|null $reply на яке повідомлення бота це відповідь
     */
    public function handleMessage($chatId, $text, $messageId = null, ?array $reply = null) {
        $this->user->setUserID($chatId);
        userLOG($chatId,'info','> '.$text);

        [$max, $window] = $this->config['rate_limit'] ?? [30, 60];
        if (!$this->limiter->allow('cmd:'.$chatId, (int)$max, (int)$window)) {
            userLOG($chatId,'warning','Rate limit exceeded');
            if ($this->limiter->allow('cmd-notice:'.$chatId, 1, 60)) {
                $this->message->sendMessage($chatId,$this->msg->getNested('main.rateLimited'));
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
            // Підписи кнопок reply-клавіатури /menu (MenuCommand::sendReply) => дія
            $menu = [
                (string)$this->msg->getNested('command.menu.full_button') => 'full',
                (string)$this->msg->getNested('command.menu.summary_button') => 'summary',
            ];
            [$kind, $arg] = $messageId !== null
                ? Router::classifyCallback((string)$text)
                : Router::classify((string)$text, $menu);
            switch ($kind) {
                case 'ev':
                    $this->user->displayEventById($arg);
                    break;

                case 'hostid':
                    // Натискання inline-кнопки хоста зі списку результатів /host
                    $token = $this->user->getUserToken();
                    if ($token !== null) {
                        (new \ZabbixBot\Commands\HostCommand())->showHost($this->message, $chatId, $token, $arg);
                    }
                    break;

                case 'menu':
                    // Натискання кнопки inline-варіанту /menu
                    switch ($arg) {
                        case 'full':
                            $this->user->displayUserEventsFull();
                            break;
                        case 'summary':
                            $this->user->displayUserEventsSummary();
                            break;
                        case 'help':
                            $this->message->sendMessage($chatId, $this->user->commandListText((array)$this->tgBot->getCommands()));
                            break;
                        case 'reset':
                            $this->message->sendMessage($chatId, $this->msg->getNested($this->user->resetCache() ? 'command.reset.done' : 'command.reset.denied'));
                            break;
                    }
                    break;

                case 'net':
                    // Кнопки Ping/Cisco/APC картки хоста (HostCommand::showHost), net:<tool>:<ip>
                    // і кнопки "Повторити" під результатом (net:ping.<count>:<host>); відповідь - на повідомлення з кнопкою
                    $net = Router::parseNet($arg);
                    switch ($net['tool']) {
                        case 'ping':
                            (new \ZabbixBot\Commands\PingCommand())->run($this->tgBot, $chatId, $net['target'], $net['count'] ?? \ZabbixBot\Services\PingService::DEFAULT_COUNT, $messageId);
                            break;
                        case 'cisco':
                            (new \ZabbixBot\Commands\CiscoCommand())->run($this->message, $chatId, $net['target']);
                            break;
                        case 'apc':
                            (new \ZabbixBot\Commands\ApcCommand())->run($this->message, $chatId, $net['target']);
                            break;
                        case 'cancel':
                            // "Скасувати" під "взято в роботу" фонового пінгу
                            (new \ZabbixBot\Commands\PingCommand())->cancel($this->tgBot, $chatId, $messageId);
                            break;
                    }
                    break;

                case 'host':
                    // Кнопки розширеної картки хоста: графіки, останні дані, події за 24 год (HostCommand::handleCallback)
                    (new \ZabbixBot\Commands\HostCommand())->handleCallback($this->message, $this->user, $chatId, $arg);
                    break;

                case 'up':
                    // "⏳" картки хоста / "Чекати ще" (up:<host>) і "Скасувати" під "чекаю" (up:x:<key>)
                    $uphost = new \ZabbixBot\Commands\UphostCommand();
                    if (str_starts_with($arg, 'x:')) {
                        $uphost->cancel($this->message, $chatId, substr($arg, 2), $messageId);
                    } else {
                        $uphost->run($this->message, $chatId, $arg, 0, $messageId);
                    }
                    break;

                case 'set':
                    // Натискання кнопки панелі налаштувань (SettingsCommand) - все через editMessage
                    $parts = explode(':', $arg);
                    $settings = new \ZabbixBot\Commands\SettingsCommand();
                    if (($parts[0] ?? '') === 'open') {
                        $settings->editOpen($this->message, $this->user, $chatId, $messageId);
                    } else {
                        $settings->applyAndRerender($this->message, $this->user, $chatId, $messageId, $parts);
                    }
                    break;

                case 'sec':
                    // Command in format /{\d+}sec
                    $dtF = new DateTime('@0');
                    $dtT = new DateTime("@$arg");
                    $this->message->sendMessage($chatId,$dtF->diff($dtT)->format($this->msg->getNested('main.dateSec')));
                    break;

                case 'hours':
                    // Command in format /{\d+}h. Old preset /24h /72h
                    $this->user->displayUserEventsFull(null,null,strtotime('-'.$arg.' hour', time()));
                    break;

                case 'menuaction':
                    // Кнопка reply-клавіатури /menu
                    if ($menu[$arg] === 'full') {
                        $this->user->displayUserEventsFull();
                    } else {
                        $this->user->displayUserEventsSummary();
                    }
                    break;

                case 'ack':
                    // Кнопка "Квитувати" під сповіщенням / /ev<id>
                    $this->user->acknowledgeEvent($arg, $messageId);
                    break;

                case 'ackmsg':
                    // Кнопка "Коментар": просимо відповісти текстом
                    $this->user->promptComment($arg, $messageId);
                    break;

                case 'text':
                    // Текстова відповідь на сповіщення/повідомлення про подію = коментар у Zabbix
                    if ($reply !== null) {
                        $this->user->commentFromReply($reply['message_id'], $reply['text'], (string)$text, $reply['own_id']);
                    }
                    break;

                case 'ignored':
                    userLOG($chatId,'warning','Unhandled callback data: '.$text);
                    break;

                case 'command':
                    //other commands to the default CommandsHandler
                    if (is_array($this->tgBot->getCommands()) && 
                        count($this->tgBot->getCommands())>1) {
                        $this->tgBot->commandsHandler(true);
                    };
                    break;
            }
        } 
        // Guest Area
        elseif ($messageId !== null || !str_starts_with($text, '/')) {
            // гостям - лише /команди текстом (callback-кнопки SDK не обробляє)
        } elseif (is_array($this->tgBot->getCommands()) && count($this->tgBot->getCommands())>1) {
            $this->tgBot->commandsHandler(true);
        }

    }
}