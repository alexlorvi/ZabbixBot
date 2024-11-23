<?php

namespace ZabbixBot\Services;

use ZabbixBot\Services\ConfigService;
use Exception;

class MsgService {
    private static $instance;
    private $def_message = [];
    private $reg_message = [];

    public function __construct() {
        $cfg = ConfigService::getInstance();
        $langFileName = implode('.', array_filter(['messages', $cfg->getNested('telegram.lang'), 'php']));
        $mainFileName = 'messages.php';
        $message_file = fixpath(MSG_PATH) . $langFileName;
        $message_file_main = fixpath(MSG_PATH) . $mainFileName;
        if (!file_exists($message_file) && !file_exists($message_file_main)) {
            throw new Exception('Messages file not exists.'.implode(',',[$message_file, $message_file_main]));
        }
        if (file_exists($message_file)) {
            $this->reg_message = include_once($message_file);
        }
        if (file_exists($message_file_main)) {
            $this->def_message = include_once($message_file_main);
        }
    }

    public static function getInstance(): MsgService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key,$default = null):mixed {
        return $this->reg_message[$key] ?? $this->def_message[$key] ?? null;
    }

    public function getNested($path, $default = null):mixed {
        return getNestedFromArray($this->reg_message,$path, getNestedFromArray($this->def_message,$path, $default));
    }

}