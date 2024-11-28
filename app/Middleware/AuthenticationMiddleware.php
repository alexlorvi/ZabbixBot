<?php

namespace ZabbixBot\Middleware;

use Telegram\Bot\Objects\Update;
use ZabbixBot\Services\ZabbixService;

class AuthenticationMiddleware implements MiddlewareInterface
{
    protected $ZabbixService;

    public function __construct(ZabbixService $ZabbixService)
    {
        $this->ZabbixService = $ZabbixService;
    }

    public function handle(Update $update, callable $next)
    {
        $userId = $update->getMessage()->getFrom()->getId();

        // Check if user is authenticated
        if (!$this->ZabbixService->isUser($userId)) {
            // Optionally, send a message to the user informing them they are not authorized
            $bot = new \Telegram\Bot\Api();
            $bot->sendMessage([
                'chat_id' => $update->getMessage()->getChat()->getId(),
                'text' => "You are not authorized to use this bot."
            ]);
            return;
        }

        // Proceed to the next middleware or command
        return $next($update);
    }
}
