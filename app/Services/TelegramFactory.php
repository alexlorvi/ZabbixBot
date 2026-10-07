<?php

namespace ZabbixBot\Services;

use Telegram\Bot\Api;
use ZabbixBot\CustomHttpClient;

/** Створення Telegram Api з секції config telegram (токен + опційний проксі) - спільне для вебхука і alert.php. */
final class TelegramFactory
{
    public static function make(array $telegramConfig): Api
    {
        $api = new Api($telegramConfig['bot_token']);
        if (isset($telegramConfig['proxy'])) {
            $httpClient = new CustomHttpClient();
            $httpClient->setProxy($telegramConfig['proxy']);
            $api->setHttpClientHandler($httpClient);
        }
        return $api;
    }
}
