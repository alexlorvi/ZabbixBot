<?php
declare(strict_types=1);

namespace ZbxBot;

use ZbxBot\Bot\Bot;
use ZbxBot\Bot\Commands;
use ZbxBot\Bot\Router;
use ZbxBot\Bot\UserTokens;
use ZbxBot\Cache\FileCache;
use ZbxBot\Cache\RateLimiter;
use ZbxBot\Cache\UpdateDeduplicator;
use ZbxBot\Log\LoggerFactory;
use ZbxBot\Net\NetTools;
use ZbxBot\Storage\TokenStore;
use ZbxBot\Telegram\GuzzleTransport;
use ZbxBot\Telegram\Messenger;
use ZbxBot\Zabbix\ZabbixClient;
use ZbxBot\Zabbix\ZabbixService;

final class Bootstrap
{
    public static function bot(array $config, string $root): Bot
    {
        date_default_timezone_set((string)($config['timezone'] ?? 'Europe/Kyiv'));
        $logs = new LoggerFactory($root.'/logs');
        $cache = new FileCache($root.'/var/cache');
        $zbx = self::zabbix($config, $cache, $logs);
        $tokens = new UserTokens(
            new TokenStore($root.'/users', $config['token_key'] ?? null),
            $zbx,
            (int)($config['user_token_ttl_days'] ?? 90),
            $logs->main(),
        );
        $commands = new Commands($zbx, $tokens, new NetTools($config, $root.'/commands/get_Int_status_cisco2.sh'));
        $router = new Router();
        $commands->register($router);

        return new Bot(
            $config,
            $router,
            $commands,
            $zbx,
            new Messenger(new GuzzleTransport($config['api_key'])),
            $logs,
            new RateLimiter($cache),
            new UpdateDeduplicator($cache),
            $cache,
        );
    }

    public static function zabbix(array $config, FileCache $cache, LoggerFactory $logs): ZabbixService
    {
        return new ZabbixService(
            new ZabbixClient($config['zbx_host'], $config['zbx_token']),
            $cache,
            $config,
            $logs->main(),
        );
    }
}
