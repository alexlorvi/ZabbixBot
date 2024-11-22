<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/tools/helpers.php';

use ZabbixBot\BotController;

$app = new BotController();

// Run script with cli perform WebHook registration
/* if (php_sapi_name() == 'cli') {
    echo $app->registerHook();
    die();
} */

$app->handleWebhook();
