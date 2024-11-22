<?php

namespace ZabbixBot\Services;

use Exception;

class ConfigService {
    private static $instance; 
    private $config;

    public function __construct() {
        $config_file = __DIR__ . '/../../config/config.php';
        if (file_exists($config_file)) {
            $this->config = require_once $config_file;
        } else {
            throw new Exception('Config file not exists.');
        }
    }

    public static function getInstance(): ConfigService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key,$default = null):mixed {
        return $this->config[$key] ?? $default;
    }

    public function getNested($path, $default = null):mixed {
        return getNestedFromArray($this->config,$path,$default);
    }
}