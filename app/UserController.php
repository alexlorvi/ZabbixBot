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
        if ($this->isZabbixUser) $this->userPrepare();
    }

    private function userPrepare(){
        $userToken = $this->user->get('zabbixToken');
        if (!isset($userToken)) {
            $zbxUserId = $this->zabbixService->getUserID($this->userID);
            userLOG($this->userID,'info','Preference Token not exist. Get it for Zabbix user #'.$zbxUserId);
            if (isset($zbxUserId)) {
                $zbxTokenId = $this->zabbixService->getUserToken($zbxUserId);
                if (!isset($zbxTokenId)) {
                    $zbxTokenId = $this->zabbixService->createUserToken($zbxUserId);
                    userLOG($this->userID,'info','Token ID not exist on Zabbix server. Create new - '.$zbxTokenId);
                } else {
                    userLOG($this->userID,'info','Found Token ID on Zabbix server - '.$zbxTokenId);
                }
                $userToken = $this->zabbixService->generateUserToken($zbxTokenId);
                if (($userToken) && (strlen($userToken)==64)) {
                    $this->user->set('zabbixToken',$userToken);
                    userLOG($this->userID,'info','New API Token Generated. '.$userToken);
                    $this->user->writeUserPreference();
                } else {
                    userLOG($this->userID,'error','New API Token Generation failed. '.$userToken);
                }
            } else {
                userLOG($this->userID,'error','Zabbix ID not found.');
            }
        } 
    }

    public function isUser(){
        return $this->isZabbixUser;
    }

}