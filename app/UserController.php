<?php

namespace ZabbixBot;

use ZabbixBot\Services\ZabbixService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Models\User;


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

    public function displayUserEventsFull($severity=[5],$group=NULL,$untilTime=NULL) {
        $events = $this->getUserEvents($severity,$group);
        if (is_array($events) && count($events)>0) {
            $this->messenger->chatActionTyping($this->userID);
            foreach($events as $event) {
                $format =$this->msg->getNested('user.UserEventsFull.Line');
                $reply = sprintf($format,
                        date('d/m/Y H:i:s',$event['clock']),
                        $event['hostName'],
                        $event['hostHost'],
                        $event['name'],
                        $event['acknowledged'] ? unichr(0x2705) : "");
                $format =$this->msg->getNested('user.UserEventsFull.ackLine');
                foreach($event['acknowledges'] as $acknowledge) {
                    $reply .= sprintf($format,
                              date('d/m/Y H:i:s',$acknowledge['clock']),
                                 $acknowledge['message'],
                                 $acknowledge['username']);
                }
                $this->messenger->sendMessage($this->userID,$reply);
            }
            $format =$this->msg->getNested('user.UserEventsFull.Count'); 
            $reply = sprintf($format,count($events));
            $this->messenger->sendMessage($this->userID,$reply);
        } else {
            $this->messenger->sendMessage($this->userID,$this->msg->getNested('user.UserEventsFull.None'));
        }
    }

    public function displayUserEventsSummary($severity=[5],$group=NULL) {
        $events = $this->getUserEvents($severity,$group);

        if (is_array($events) && count($events)>0) {
            $this->messenger->chatActionTyping($this->userID);
            $message = '';
            foreach($events as $event) {
                $format =$this->msg->getNested('user.UserEventsSummary.Line'); 
                $reply = sprintf($format,
                                date('d/m/Y H:i:s',$event['clock']),
                                $event['eventid'],
                                $event['hostName'],
                                $event['hostHost']);
                $message .= $reply.PHP_EOL;
            }
            $this->messenger->sendMessage($this->userID,$message);
            $format =$this->msg->getNested('user.UserEventsSummary.Count'); 
            $reply = sprintf($format,count($events));
            $this->messenger->sendMessage($this->userID,$reply);
        } else {
            $this->messenger->sendMessage($this->userID,$this->msg->getNested('user.UserEventsSummary.None'));
        }
    }

    public function displayEventById($eventID){
        $eventInfo = $this->zabbixService->getEventInfo($eventID);
        if (is_array($eventInfo)) {
            $format =$this->msg->getNested('user.EventById.Line'); 
            $reply = sprintf($format,
            date('d/m/Y H:i:s',$eventInfo['clock']),
                    $eventInfo['hosts']['0']['host'],
                    $eventInfo['hosts']['0']['name'],
                    $eventInfo['name'],
                    ($eventInfo['acknowledged'] ? unichr(0x2705) : ""));

            $format =$this->msg->getNested('user.EventById.ackLine');

            foreach($eventInfo['acknowledges'] as $acknowledge) {
                $reply .= sprintf($format,
                          date('d/m/Y H:i:s',$acknowledge['clock']),
                             $acknowledge['message'],
                             $acknowledge['username']);
            }
          
            $this->messenger->sendMessage($this->userID,$reply);
        }
    }

    private function getUserEvents($severity=[5],$group=NULL,$untilTime=NULL) {
        $userEvents = $this->zabbixService->getUserProblems($this->user->get('zabbixToken'),$severity,$group,$untilTime);
        userLOG($this->userID,'debug',print_r($userEvents));
        $responce = [];
        if (is_array($userEvents) && count($userEvents)>0) {
            foreach($userEvents as $event) {
                if (isset($event['eventid'])) {
                    $eventInfo = $this->zabbixService->getEventInfo($event['eventid']);
                    userLOG($this->userID,'debug',print_r($eventInfo));
                    $responce[] = [
                        'eventid' => $event['eventid'],
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
        }
        return $responce;
    }

    public function isUser(){
        return $this->isZabbixUser;
    }

}