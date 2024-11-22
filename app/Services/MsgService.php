<?php

namespace ZabbixBot\Services;

use ZabbixBot\Services\ConfigService;
use Exception;

class MsgService {
    private static $instance; 
    private $config;

    public function __construct() {
        $cfg = ConfigService::getInstance();
        $lang = $cfg->getNested('telegram.lang');
        $config_file = __DIR__ . implode('.', array_filter(['/../../config/messages', $lang, '.php']));
        $config_file_main = __DIR__ . implode('.', array_filter(['/../../config/messages', null, '.php']));
        if (file_exists($config_file)) {
            $this->config = require $config_file;
        } elseif (file_exists($config_file_main)) {
            $this->config = require $config_file_main;
        } else{
            throw new Exception('Config file not exists.');
        }
    }

    public static function getInstance(): MsgService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key,$default = null):mixed {
        return $this->config[$key] ?? $default;
    }

    public function getNested($path, $default = null):mixed {
        $keys = explode('.', $path);
        $value = $this->config;
        foreach ($keys as $key) {
            if (isset($value[$key])) {
                $value = $value[$key];
            } else {
                return $default;
            }
        }
        return $value;
    }
}