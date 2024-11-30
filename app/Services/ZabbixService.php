<?php

namespace ZabbixBot\Services;

use IntelliTrend\Zabbix\ZabbixApi;
use IntelliTrend\Zabbix\ZabbixApiException;
use ZabbixBot\Services\ConfigService;
use Exception;

class ZabbixService {

    private string $zabbixHost;
    private string $zabbixKey;
    private ZabbixApi $zabbixApi;

    public function __construct() {
        $cfg = ConfigService::getInstance();
        $this->zabbixHost = $cfg->getNested('zabbix.host');
        $this->zabbixKey = $cfg->getNested('zabbix.apikey');
        $this->zabbixApi = new ZabbixApi();
    }

    public function isUser(string $userID):bool {
        $result = $this->request('user.get',[
            'output'=>['userid', 'username','name','surname','active'],
            'selectMedias'=>['mediatypeid','sendto','active','severity'],
            'mediatypeids'=>'16',
            'filter'=>[
                'mediatypeid'=>'16',
                'active'=>'0',
                'sendto'=>$userID,
            ],
        ]);
        if (is_array($result)) {
            foreach($result as $user) {
                if (is_array($user['medias'])) {
                    foreach($user['medias'] as $media) {
                        if ($media['sendto'] == $userID) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    public function getUserInfo($userID) {
        $result = $this->request('user.get',[
            'output'=>['userid', 'username','name','surname','active'],
            'selectMedias'=>['mediatypeid','sendto','active','severity'],
            'mediatypeids'=>'16',
            'filter'=>[
                'mediatypeid'=>'16',
                'active'=>'0',
                'sendto'=>$userID
            ],
        ]);
        $reply = "Здається ми не знайомі.";
        if (is_array($result)) {
            foreach($result as $user) {
                if (is_array($user['medias'])) {
                    foreach($user['medias'] as $media) {
                        if ($media['sendto'] == $userID) {
                            $reply  = '*Info:*'.PHP_EOL;
                            $reply .= '*Username* '.$user['username'].PHP_EOL;
                            $reply .= '*Name*     '.$user['name'].PHP_EOL;
                            $reply .= '*SurName*  '.$user['surname'].PHP_EOL;
                            $reply .= '*severity* '.$media['severity'].PHP_EOL;
                        }
                    }
                }
            }
        }
        return $reply;
    }

    public function getUserID($userID) {
        $result = $this->request('user.get',[
            'output'=>['userid', 'username','name','surname','active'],
            'selectMedias'=>['mediatypeid','sendto','active','severity'],
            'mediatypeids'=>'16',
            'filter'=>[
                'mediatypeid'=>'16',
                'active'=>'0',
                'sendto'=>$userID
            ],
        ]);
        if (is_array($result)) {
            foreach($result as $user) {
                if (is_array($user['medias'])) {
                    foreach($user['medias'] as $media) {
                        if ($media['sendto'] == $userID) {
                            return $user['userid'];
                        }
                    }
                }
            }
        }
        return null;
    }

    public function getUserToken($userID):string {
        $result = $this->request('token.get',[
            'filter'=>[
                'name'=>'zbx_bot'
            ],
            'userids'=>$userID
        ]);
        return (is_array($result) && isset($result[0]['tokenid'])) ? $result[0]['tokenid'] : '';
    }

    public function createUserToken($userID) {
        $result = $this->request('token.create',[
            'name'=>'zbx_bot',
            'userid'=>$userID
        ]);
        return (is_array($result)) ? $result['tokenids']['0'] : null;
    }

    public function generateUserToken($userID) {
        $result = $this->request('token.generate',[$userID]);
        return (is_array($result)) ? $result['0']['token'] : null;
    }

    public function getUserProblems(string $userToken,$severity=['5'],$groupID=NULL,$timeTill=NULL) {
        $request = [
            'output' => ['eventid','clock','name'],
            'severities' => $severity,
            'sortfield' => 'eventid',
            'sortorder' => 'DESC'
        ];
        if (isset($groupID)) $request['groupids'] = $groupID;
        if (isset($timeTill)) $request['time_till'] = $timeTill;
        $result = $this->request('problem.get',$request,$userToken);
        return (is_array($result)) ? $result : null;
    }

    public function getEventInfo($eventID){
        $result = $this->request('event.get',[
            'output' => ['acknowledged','name','clock'],
            'select_acknowledges' => ['clock','message','username'],
            'selectTags' => 'extend',
            'selectHosts' => ['host','name'],
            'eventids' => $eventID
          ]);
        return (is_array($result[0])) ? $result[0] : null;
    }

    public function getGroups($withHosts=true, $userToken=NULL) {
        $request['output'] = ['groupid','name'];
        if ($withHosts) {
            $request['real_hosts'] = $withHosts;
        }
        $result = $this->request('hostgroup.get',[$request],$userToken);
        return (is_array($result)) ? $result : null;
    }

    public function getGroupIdByName(string $groupName){
        $groups = $this->getGroupIdByName(false);
        if (is_array($groups)){
            foreach($groups as $group){
                if (strcasecmp($group["name"],$groupName)==0) {
                    return $group["groupid"];
                };            
            }
        }
        return null;
    }

    public function getHostsByGroup($groupID) {
        $result = $this->request('host.get',[
            'groupids' => $groupID,
            'output' => ['hostid','host','status','name'],
            'selectGroups' => ['groupid','name'],
            'selectInventory' => ['tag'],
        ]);
        return (is_array($result)) ? $result : null;
    }

    public function massRemoveHostGroup($groupID,$hosts) {
        $result = $this->request('hostgroup.massremove',[
            'groupids' => $groupID,
            'hostids' => $hosts,
        ]);
        return (is_array($result)) ? $result : null;
    }

    public function massAddHostGroup($groupID,$hosts) {
        $result = $this->request('hostgroup.massadd',[
            'groups' => [
              'groupid' => $groupID,
            ],
            'hosts' => $hosts,
        ]);
        return (is_array($result)) ? $result : null;
    }

    private function request(string $zabbixMethod, array $params = [],string $userToken = null) {
        try {
            $token = $userToken ?? $this->zabbixKey;
            $this->zabbixApi->loginToken($this->zabbixHost, $token);
            $result = $this->zabbixApi->call($zabbixMethod,$params);
            return $result;
        } catch (ZabbixApiException $ae) {
            mainLOG('zabbix','error','ApiException: '.$ae->getCode().'. ErrorMessage: '.$ae->getMessage());
        } catch (Exception $e) {
            mainLOG('zabbix','error','Errorcode: '.$e->getCode().'. ErrorMessage: '.$e->getMessage());
        }
        return null;
    }

}