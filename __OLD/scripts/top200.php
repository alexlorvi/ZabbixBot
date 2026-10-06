<?php
declare(strict_types=1);

// Синхронізація групи TOP200 з роутерів WogRouters. Запуск тільки з консолі (cron).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__.'/../vendor/autoload.php';

use ZbxBot\Bootstrap;
use ZbxBot\Cache\FileCache;
use ZbxBot\Log\LoggerFactory;
use ZbxBot\Top200Sync;
use ZbxBot\Zabbix\ZabbixException;

$root = dirname(__DIR__);
$config = require $root.'/config/config.php';
$logs = new LoggerFactory($root.'/logs');
$log = $logs->main();
$zbx = Bootstrap::zabbix($config, new FileCache($root.'/var/cache'), $logs);

$routersGroup = $zbx->groupId('WogRouters');
$topGroup = $zbx->groupId('TOP200');
if ($routersGroup === null || $topGroup === null) {
    $log->error('top200: group WogRouters/TOP200 not found. Abort');
    exit(1);
}

try {
    $routers = $zbx->hostsByGroup($routersGroup);
    if (!$routers) {
        $log->error('top200: WogRouters is empty. Abort, TOP200 untouched');
        exit(1);
    }
    $plan = Top200Sync::plan($routers, array_column($zbx->hostsByGroup($topGroup), 'hostid'));
    // Міняємо тільки різницю - група ніколи не буває порожньою посеред оновлення
    if ($plan['remove']) {
        $zbx->removeHostsFromGroup($topGroup, $plan['remove']);
    }
    if ($plan['add']) {
        $zbx->addHostsToGroup($topGroup, $plan['add']);
    }
    $log->info(sprintf('top200 synced: +%d -%d (in scope %d)', count($plan['add']), count($plan['remove']), $plan['wanted']));
} catch (ZabbixException $e) {
    $log->error('top200: '.$e->getMessage());
    exit(1);
}
