<?php

require_once __DIR__.'/config/constants.php';
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/tools/helpers.php';

use Symfony\Component\Console\Application;
use ZabbixBot\Commands\CLI\RetryMessagesCommand;
use ZabbixBot\Commands\CLI\SendMessagesCommand;
use ZabbixBot\Commands\CLI\Top200SyncCommand;
use ZabbixBot\CustomHttpClient;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\ZabbixService;
use Telegram\Bot\Api;


// Run only on CLI mode
if (php_sapi_name() != 'cli') {
    die();
};

$config = ConfigService::getInstance()->getNested('telegram');

// Initialize the Telegram API
$telegram = new Api($config['bot_token']);
if (isset($config['proxy'])) {
    $httpClient = new CustomHttpClient();
    $httpClient->setProxy($config['proxy']);
    $telegram->setHttpClientHandler($httpClient);
}

// Initialize the MessageService
$messageService = new MessageService($telegram);

// Create the Console Application
$application = new Application();

// Register CLI commands
$application->add(new RetryMessagesCommand($messageService));
$application->add(new SendMessagesCommand($messageService));
$application->add(new Top200SyncCommand(new ZabbixService()));

// Run the application
$application->run();



///
/// php console.php app:retry-messages --limit=5
/// php console.php app:send-message <chatId> "<message>"
/// php console.php app:top200-sync
///