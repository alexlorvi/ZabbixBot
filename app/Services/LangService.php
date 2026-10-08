<?php

namespace ZabbixBot\Services;

use ZabbixBot\Services\ConfigService;
use Exception;

class LangService {
    private static $instance;
    private array $messages = [];
    private array $languages = [];
    private string $currentLanguage;
    private string $defaultName = 'en';

    public function __construct() {
        $this->readLangFiles($this->defaultName);
        $cfg = ConfigService::getInstance();
        $this->setLang($cfg->getNested('telegram.lang'));
    }

    private function readLangFiles(string $defaultName){
        foreach(glob(MSG_PATH.'/messages*.php') as $filename) {
            $tmp_arr = array_diff(explode('.',basename($filename)),['messages','php']);
            if (count($tmp_arr)>0) {
                $langName = $tmp_arr[1];
                $this->languages[] = $langName;
                $this->messages[$langName] = require realpath($filename);
            } else {
                $this->languages[] = $defaultName;
                $this->messages[$defaultName] = require realpath($filename);
            }
        }
        $this->currentLanguage = $defaultName;
    }

    public function setLang($strLang) {
        if (in_array($strLang,$this->languages)) $this->currentLanguage = $strLang;
    }

    public function getLang(): string {
        return $this->currentLanguage;
    }

    public static function getInstance(): LangService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getNested($path, $default = ''):mixed {
        return getNestedFromArray($this->messages[$this->currentLanguage],$path, getNestedFromArray($this->messages[$this->defaultName],$path, $default));
    }

}