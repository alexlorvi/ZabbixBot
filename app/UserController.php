<?php

namespace ZabbixBot;

use ZabbixBot\Services\ZabbixService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Models\User;

class UserController {
    protected ZabbixService $zabbixService;
    protected LangService $msg;
    protected int $userID;
    protected bool $isZabbixUser = false;
    protected User $user;

    public function __construct($userID = null) {
        $this->zabbixService = new ZabbixService();
        $this->msg = LangService::getInstance();
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
        if (!isset($userToken)) $this->userApiTokenGeneration();
    }

    private function userApiTokenGeneration() {
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

    public function userEventsSummary($severity=[5],$group=NULL,$countTotal = true) {
        $userEvents = $this->zabbixService->getUserProblems($this->user->get('zabbixToken'),$severity);
        $responce = [];
        if (is_array($userEvents) && count($userEvents)>0) {
            $eventsSum = 0;
            foreach($userEvents as $event) {
                $line = '';
                if (isset($event['eventid'])) {
                    $eventInfo = $this->zabbixService->getEventInfo($event['eventid']);
                    $line .= date('d/m/Y H:i:s',$event['clock']);
                    //$line .= " - ".$eventInfo['hosts']['0']['host']." (".$event['hosts']['0']['name']." )";
                    //$line .= " - ".$eventInfo['hosts']['0']['host'];
                    $line .= " - /ev".$event['eventid'].PHP_EOL;
                    $line .= $eventInfo['hosts']['0']['name'].PHP_EOL;
                    $line .= $this->msg->getNested('helpers.preatyline') .PHP_EOL;
                }
                $responce[] = $line;
                $eventsSum += 1;
            }
            if ($countTotal) $responce[] = 'Total count - '.$eventsSum;
        } else {
            $responce[] = '';
        }
        return $responce;
    }

    public function isUser(){
        return $this->isZabbixUser;
    }

}