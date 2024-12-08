<?php

require_once __DIR__.'/config/constants.php';
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/tools/helpers.php';

use Symfony\Component\Console\Application;
use ZabbixBot\Command\RetryMessagesCommand;
use ZabbixBot\Services\MessageService;
use Telegram\Bot\Api;

// Initialize the Telegram API
$telegram = new Api('YOUR_TELEGRAM_BOT_TOKEN');

// Initialize the MessageService
$messageService = new MessageService($telegram);

// Create the Console Application
$application = new Application();

// Register the RetryMessagesCommand
$application->add(new RetryMessagesCommand($messageService));

// Run the application
$application->run();



///
/// php console.php app:retry-messages --limit=5
///