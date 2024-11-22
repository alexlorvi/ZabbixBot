<?php

namespace ZabbixBot\Services;

use ZabbixBot\Services\ConfigService;
use Exception;

class MsgService {
    private static $instance;
    private $message;

    public function __construct() {
        $cfg = ConfigService::getInstance();
        $langFileName = implode('.', array_filter(['messages', $cfg->getNested('telegram.lang'), 'php']));
        $mainFileName = 'messages.php';
        $message_file = __DIR__ . '/../../config/' . $langFileName;
        $message_file_main = __DIR__ . '/../../config/' . $mainFileName;
        if (file_exists($message_file)) {
            $this->message = require_once $message_file;
        } elseif (file_exists($message_file_main)) {
            $this->message = require_once $message_file_main;
        } else{
            throw new Exception('Messages file not exists.'.PHP_EOL.$message_file.PHP_EOL.$message_file_main);
        }
    }

    public static function getInstance(): MsgService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key,$default = null):mixed {
        return $this->message[$key] ?? $default;
    }

    public function getNested($path, $default = null):mixed {
        return getNestedFromArray($this->message,$path, $default);
    }
}