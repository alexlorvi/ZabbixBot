<?php

namespace ZabbixBot\Services;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class LoggerService {

    private static $instance;
    private $loggers = [];

    public function __construct() {
        $this->initLoggers();
    }

    public static function getInstance(): LoggerService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initLoggers() {
        $loggerConfig = ConfigService::getInstance()->getNested('logger');
        $keepDays = (int)($loggerConfig['keep_days'] ?? 30);
        foreach(['main','zabbix'] as $loggerName) {
            $tmpLogger = new Logger($loggerName);
            $handler = new RotatingFileHandler(fixpath($loggerConfig['file_path']).$loggerConfig['main_name'], $keepDays, Logger::toMonologLevel($loggerConfig['main_level']), true, 0640);
            $tmpLogger->pushHandler($handler);
            $this->loggers[$loggerName] = $tmpLogger;
        }
    }

    public function log(string $loggerType,string $level, $message, array $context = []):void {
        if (isset($this->loggers[$loggerType])) { 
            $this->loggers[$loggerType]->log($level, $message, $context); 
        }
    }

    public function getLogger($loggerType):mixed {
        return $this->loggers[$loggerType] ?? null;
    }
    
    public function createUserLogger($userId) {
        if (!isset($this->loggers[$userId])) {
            $loggerConfig = ConfigService::getInstance()->getNested('logger');
            $keepDays = (int)($loggerConfig['keep_days'] ?? 30);
            $userLogger = new Logger('user_' . $userId);
            $handler = new RotatingFileHandler(fixpath($loggerConfig['file_path']).'user_' . $userId . '.log', $keepDays, Logger::toMonologLevel($loggerConfig['user_level']), true, 0640);
            // Optional: Customize the log format
            $formatter = new LineFormatter(null, null, true, true);
            $handler->setFormatter($formatter);
            $userLogger->pushHandler($handler);
            $this->loggers[$userId] = $userLogger;
        }
        return $this->loggers[$userId];
    }

}