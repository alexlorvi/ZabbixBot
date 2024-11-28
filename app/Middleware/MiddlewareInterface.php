<?php

namespace ZabbixBot\Middleware;

use Telegram\Bot\Objects\Update;

interface MiddlewareInterface
{
    public function handle(Update $update, callable $next);
}
