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

    public function isZabbixUser(string $userID) {
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

    public function getZbxUserInfo($userID) {
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
        $found = false;
        if (is_array($result)) {
            foreach($result as $user) {
                if (is_array($user['medias'])) {
                    foreach($user['medias'] as $media) {
                        if ($media['sendto'] == $userID) {
                            $reply  = '*Username* '.$user['username'].PHP_EOL;
                            $reply .= '*Name*     '.$user['name'].PHP_EOL;
                            $reply .= '*SurName*  '.$user['surname'].PHP_EOL;
                            $reply .= '*severity* '.$media['severity'].PHP_EOL;
                            $found = true;
                        }
                    }
                }
            }
        }
        if ($found) {
          return '*Info:*'.PHP_EOL.$reply;
        } else {
          return "Здається ми не знайомі.";
        }
    }

    public function getZbxUserID($userID) {
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
        $found = false;
        if (is_array($result)) {
            foreach($result as $user) {
                if (is_array($user['medias'])) {
                    foreach($user['medias'] as $media) {
                        if ($media['sendto'] == $userID) {
                            $reply = $user['userid'];
                            $found = true;
                        }
                    }
                }
            }
        }
        return ($found) ? $reply : null;
    }

    public function getZbxUserToken($userID) {
        $result = $this->request('token.get',[
            'filter'=>[
                'name'=>'zbx_bot'
            ],
            'userids'=>$userID
        ]);
        return (is_array($result) && isset($result[0]['tokenid'])) ? $result[0]['tokenid'] : null;
    }

    public function createZbxUserToken($userID) {
        $result = $this->request('token.create',[
            'name'=>'zbx_bot',
            'userid'=>$userID
        ]);
        return (is_array($result)) ? $result['tokenids']['0'] : null;
    }

    public function generateZbxUserToken($userID) {
        $result = $this->request('token.generate',[$userID]);
        return (is_array($result)) ? $result['0']['token'] : null;
    }

    private function request(string $zabbixMethod, array $params = []) {
        try {
            $this->zabbixApi->loginToken($this->zabbixHost, $this->zabbixKey);
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