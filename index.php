<?php

require_once __DIR__.'/config/constants.php';
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/tools/helpers.php';

use ZabbixBot\BotController;

$app = new BotController();
$app->handleWebhook();
