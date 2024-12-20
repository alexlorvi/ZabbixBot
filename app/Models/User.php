<?php

namespace ZabbixBot\Models;

use Exception;

class User {

    protected string $userID;
    protected string $userPrefFile;
    protected array $userPreferences;

    public function __construct($userID) {
        $this->userID = $userID;
        if (!file_exists(USER_PREF_PATH)) mkdir(USER_PREF_PATH,0777,true);
        $this->userPrefFile = $this->getFilePath();
        $this->userPreferences = $this->readUserPreference();
    }

    private function getFilePath() {
        return fixpath(USER_PREF_PATH).'user_'.$this->userID.'.json';
    }

    public function readUserPreference() {
        if (file_exists($this->userPrefFile)) {
            $json = file_get_contents($this->userPrefFile);
            return json_decode($json, true);
        }
        return [];
    }
    
    public function writeUserPreference():void {
        try {
            file_put_contents($this->userPrefFile, json_encode($this->userPreferences)); 
            userLOG($this->userID,'info','Save preferences into file. '.$this->userPrefFile);
        } catch (Exception $e) {
            mainLOG('main','error','Error write file '.$this->userPrefFile.' '.$e->getMessage());
        }
    }

    public function get($key,$default = null):string|null {
        return $this->userPreferences[$key] ?? $default;
    }

    public function set($key,$value):void {
        $this->userPreferences[$key] = $value;
    }

}