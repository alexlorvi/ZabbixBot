<?php

namespace ZabbixBot\Services;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LoggerService {

    private static $instance;
    private static Logger $logger;

    public function __construct() {
        $loggerConfig = ConfigService::getInstance()->getNested('logger');

        self::$logger = New Logger('telegram_bot');
        self::$logger->pushHandler(new StreamHandler($loggerConfig['file_path'],Logger::toMonologLevel($loggerConfig['level'])));
    }

    public static function getInstance(): LoggerService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function error($message, array $context = []) {
        self::$logger->error($message, $context);
    }
    public static function info($message, array $context = []) {
        self::$logger->info($message, $context);
    }
    public static function warning($message, array $context = []) {
        self::$logger->warning($message, $context);
    }

    public static function log($level, $message, array $context = []) {
        self::$logger->log($level, $message, $context);
    }

}