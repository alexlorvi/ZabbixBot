<?php

namespace ZabbixBot;

use ZabbixBot\Services\ZabbixService;
use ZabbixBot\Models\User;

class UserController {
    protected ZabbixService $zabbixService;
    protected int $userID;
    protected bool $isZabbixUser = false;
    protected User $user;

    public function __construct($userID = null) {
        $this->zabbixService = new ZabbixService();
        if (isset($userID)) $this->setUserID($userID);
    }

    public function setUserID($userID) {
        $this->userID = $userID;
        $this->isZabbixUser = $this->zabbixService->isUser($this->userID);
        $this->user = New User($this->userID);
    }

    public function isUser(){
        return $this->isZabbixUser;
    }

}