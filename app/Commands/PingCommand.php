<?php

namespace ZabbixBot\Commands;

use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command as TgCommand;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\Services\PingService;

class PingCommand extends TgCommand {
    protected string $name = 'ping';
    protected string $description = 'Ping Command to check network connectivity';
    protected string $pattern = '{host} {count: \d+}';
    protected PingService $pingService;

    public function __construct() {
        $this->pingService = new PingService();
    }
    public function handle()
    {
        $host = $this->argument('host');
        $count = $this->argument('count', 4);

        if (!$host) {
            try { 
                $reply = emoji('warn') . " Host or IP not specified.\r\n*Usage:* /ping {HOST/IP} _{optional You can set count, default is 4}_\r\n*Example:*\r\n/ping google.com\r\n/ping 8.8.8.8 20";
                $message = $this->replyWithMessage([
                    'text' => $reply,
                    'parse_mode' => 'markdown',
                ]);
                userLOG($message->getChat()->getId(),'info','< Command Ping help reply');
            } catch (TelegramOtherException $e) { 
                mainLOG('main','error',"Telegram Error: " . $e->getMessage()); 
            } catch (\Exception $e) { 
                mainLOG('main','error',"General Error: " . $e->getMessage()); 
            }
        } else {
            $message = $this->replyWithMessage(['text' => "Pinging $host..."]);

            $messageId = $message->getMessageId(); 
            $chatId = $message->getChat()->getId();

            userLOG($chatId,'info',"< Ping command for host: $host");

            $this->replyWithChatAction(['action' => Actions::TYPING]);

            $pingResults = "";
            $callback = function ($line) use (&$pingResults, $chatId, $messageId) { 
                $pingResults .= $line; 
                try { 
                    $this->telegram->editMessageText([ 
                        'chat_id' => $chatId, 
                        'message_id' => $messageId, 
                        'text' => $pingResults 
                    ]); 
                } catch (TelegramOtherException $e) { 
                    mainLOG('main','error',"Telegram Error: " . $e->getMessage()); 
                } catch (\Exception $e) { 
                    mainLOG('main','error',"General Error: " . $e->getMessage()); 
                }
            };
            $this->pingService->ping($host,$count,$callback);
        }
    }
}