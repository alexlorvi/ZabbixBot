<?php

require_once __DIR__.'/config/constants.php';
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/tools/helpers.php';

use Symfony\Component\Console\Application;
use ZabbixBot\Commands\CLI\AlertCleanupCommand;
use ZabbixBot\Commands\CLI\MediaTypeCommand;
use ZabbixBot\Commands\CLI\PingJobCommand;
use ZabbixBot\Commands\CLI\RetryMessagesCommand;
use ZabbixBot\Commands\CLI\SendMessagesCommand;
use ZabbixBot\Commands\CLI\Top200SyncCommand;
use ZabbixBot\Commands\CLI\UphostJobCommand;
use ZabbixBot\Commands\CLI\WebhookCommand;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\TelegramFactory;
use ZabbixBot\Services\ZabbixService;


// Run only on CLI mode
if (php_sapi_name() != 'cli') {
    die();
};

$config = ConfigService::getInstance()->getNested('telegram');

// Initialize the Telegram API
$telegram = TelegramFactory::make($config);

// Initialize the MessageService
$messageService = new MessageService($telegram);

// Create the Console Application
$application = new Application();

// Register CLI commands
$application->add(new RetryMessagesCommand($messageService));
$application->add(new SendMessagesCommand($messageService));
$application->add(new Top200SyncCommand(new ZabbixService()));
$application->add(new WebhookCommand());
$application->add(new MediaTypeCommand(new ZabbixService()));
$application->add(new PingJobCommand($messageService)); // прихована: фоновий /ping > 100 пакетів
$application->add(new UphostJobCommand($messageService)); // прихована: фонове очікування /uphost
$application->add(new AlertCleanupCommand($messageService));

// Run the application
$application->run();



///
/// php console.php app:retry-messages --limit=5
/// php console.php app:send-message <chatId> "<message>"
/// php console.php app:top200-sync
/// php console.php app:webhook set|info|del
/// php console.php app:alert-cleanup --hours=24   # cron: видалити сповіщення подій, відновлених >= N год тому (1-47)
/// php console.php app:mediatype install --lang=ua|en [--mediatype-id=N] [--dry-run]   # створити (або замінити N), друкує mediatype-id
/// php console.php app:mediatype export --lang=ua|en [-o file] [--placeholders]       # лише YAML для імпорту, Zabbix не чіпає
///