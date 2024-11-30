<?php

namespace ZabbixBot;

use ZabbixBot\Services\ZabbixService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Models\User;
use Telegram\Bot\Actions;


class UserController {
    protected ZabbixService $zabbixService;
    protected LangService $msg;
    protected MessageService $messenger;
    protected int $userID;
    protected bool $isZabbixUser = false;
    protected User $user;

    public function __construct(MessageService $message, $userID = null) {
        $this->messenger = $message;
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

        $userLang = $this->user->get('lang');
        if (!isset($userToken)) {
            $cfg = ConfigService::getInstance();
            $this->user->set('lang',$cfg->getNested('telegram.lang'));
            $this->user->writeUserPreference();
        } else {
            $this->msg->setLang($userLang);
        }
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

    public function listUserEventsSummary($severity=[5],$group=NULL) {
        $events = $this->getUserEvents($severity,$group);

        if (is_array($events) && count($events)>0) {
            //$this->messenger->chatAction(['action' => Actions::TYPING]);
            $message = '';
            foreach($events as $event) {
                $format =$this->msg->getNested('user.listEvents.sumLine'); 
                $reply = sprintf($format,
                                date('d/m/Y H:i:s',$event['clock']),
                                $event['eventid'],
                                $event['hostName']);
                $message .= $reply.PHP_EOL;
            }
            $this->messenger->sendMessage($this->userID,$message);
            $format =$this->msg->getNested('user.listEvents.sumCount'); 
            $reply = sprintf($format,count($events));
            $this->messenger->sendMessage($this->userID,$reply);
        } else {
            $this->messenger->sendMessage($this->userID,$this->msg->getNested('user.listEvents.None'));
        }
    }

    private function getUserEvents($severity=[5],$group=NULL,$untilTime=NULL) {
        $userEvents = $this->zabbixService->getUserProblems($this->user->get('zabbixToken'),$severity,$group,$untilTime);
        $responce = [];
        if (is_array($userEvents) && count($userEvents)>0) {
            foreach($userEvents as $event) {
                if (isset($event['eventid'])) {
                    $eventInfo = $this->zabbixService->getEventInfo($event['eventid']);
                    $responce[] = [
                        'id' => $event['eventid'],
                        'name' => $event['name'],
                        'clock' => $event['clock'],
                        'hostName' => $eventInfo['hosts']['0']['name'] ?? '',
                        'hostHost' => $eventInfo['hosts']['0']['host'] ?? '',
                        'acknowledged' => $eventInfo['acknowledged'] ?? '',
                        'acknowledges' => $eventInfo['acknowledges'] ?? '',
                        'tags' => $eventInfo['tags'] ?? '',
                    ];
                }
            }
        } else {
            return $responce;
        }
    }

    public function isUser(){
        return $this->isZabbixUser;
    }

}