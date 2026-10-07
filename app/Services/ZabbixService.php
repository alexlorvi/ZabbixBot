<?php

namespace ZabbixBot\Services;

use IntelliTrend\Zabbix\ZabbixApi;
use IntelliTrend\Zabbix\ZabbixApiException;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\FileCache;
use Exception;

class ZabbixService {

    private string $zabbixHost;
    private string $zabbixKey;
    private ZabbixApi $zabbixApi;
    private FileCache $cache;

    public function __construct() {
        $cfg = ConfigService::getInstance();
        $this->zabbixHost = $cfg->getNested('zabbix.host');
        $this->zabbixKey = $cfg->getNested('zabbix.apikey');
        $this->zabbixApi = new ZabbixApi();
        $this->cache = new FileCache(CACHE_PATH);
    }

    /**
     * Користувачі Zabbix з медіа Telegram: chat id => дані. Кеш у файлі (zabbix.user_cache_ttl, 300с);
     * якщо Zabbix недоступний - віддаємо застарілий кеш. null - даних немає взагалі.
     * @return array<string,array<string,string>>|null
     */
    public function telegramUsers(): ?array {
        $cfg = ConfigService::getInstance();
        $ttl = (int)$cfg->getNested('zabbix.user_cache_ttl', 300);
        $mediaTypeId = (string)$cfg->getNested('zabbix.mediatype_id', '16');

        $fresh = $this->cache->get('zbx:users', $ttl);
        if ($fresh !== null) {
            return $fresh;
        }
        $users = $this->request('user.get',[
            'output'=>['userid', 'username','name','surname'],
            'selectMedias'=>['mediatypeid','sendto','active','severity'],
            'selectUsrgrps'=>['name'],
            'mediatypeids'=>$mediaTypeId,
        ]);
        if (!is_array($users)) {
            return $this->cache->get('zbx:users', PHP_INT_MAX);
        }
        $map = [];
        foreach ($users as $user) {
            foreach ((array)($user['medias'] ?? []) as $media) {
                $sendto = trim((string)($media['sendto'] ?? ''));
                if ($sendto !== '' && !isset($map[$sendto])) {
                    $map[$sendto] = [
                        'userid' => (string)$user['userid'],
                        'username' => (string)($user['username'] ?? ''),
                        'name' => (string)($user['name'] ?? ''),
                        'surname' => (string)($user['surname'] ?? ''),
                        'severity' => (string)($media['severity'] ?? ''),
                        'usrgrps' => array_column((array)($user['usrgrps'] ?? []), 'name'),
                    ];
                }
            }
        }
        $this->cache->set('zbx:users', $map);
        return $map;
    }

    /** @return array<string,mixed>|null */
    public function findUser(string $userID): ?array {
        return ($this->telegramUsers() ?? [])[$userID] ?? null;
    }

    /**
     * Чи входить Zabbix-користувач (за chat id) у групу адмінів бота (config: zabbix.admin_group).
     * Членство в групі - єдине джерело правди для адмінських команд (/reset), без окремого списку в конфізі.
     */
    public function isAdmin(string $chatId): bool {
        $adminGroup = (string)ConfigService::getInstance()->getNested('zabbix.admin_group', '');
        if ($adminGroup === '') {
            return false;
        }
        $user = $this->findUser($chatId);
        return $user !== null && in_array($adminGroup, $user['usrgrps'] ?? [], true);
    }

    public function resetUserCache(): void {
        $this->cache->delete('zbx:users');
        $this->cache->delete('zbx:groups');
    }

    public function isUser(string $userID):bool {
        return $this->findUser($userID) !== null;
    }

    public function getUserInfo($userID) {
        $user = $this->findUser($userID);
        if ($user === null) {
            return "Здається ми не знайомі.";
        }
        $reply  = '*Info:*'.PHP_EOL;
        $reply .= '*Username* '.$user['username'].PHP_EOL;
        $reply .= '*Name*     '.$user['name'].PHP_EOL;
        $reply .= '*SurName*  '.$user['surname'].PHP_EOL;
        $reply .= '*severity* '.$user['severity'].PHP_EOL;
        return $reply;
    }

    public function getUserID($userID) {
        return $this->findUser($userID)['userid'] ?? null;
    }

    /**
     * Усі налаштовані методи сповіщення (media) Zabbix-користувача, без фільтра по типу медіа.
     * Викликається сервісним ключем (адмінські права потрібні для редагування чужого user-об'єкта).
     * @return list<array<string,mixed>>
     */
    public function getUserMediasFull(string $userId): array {
        $result = $this->request('user.get', [
            'userids' => $userId,
            'output' => ['userid'],
            'selectMedias' => ['mediatypeid', 'sendto', 'active', 'severity', 'period'],
        ]);
        return (is_array($result) && isset($result[0]['medias'])) ? $result[0]['medias'] : [];
    }

    /**
     * ID типів сповіщень (mediatype) для "виду" каналу: 'tg' - Telegram-медіа бота (zabbix.mediatype_id),
     * 'email' - усі Zabbix mediatype з type=0 (Email).
     * @return list<string>
     */
    public function mediaTypeIdsForKind(string $kind): array {
        if ($kind === 'tg') {
            return [(string)ConfigService::getInstance()->getNested('zabbix.mediatype_id', '16')];
        }
        if ($kind === 'email') {
            $types = $this->request('mediatype.get', ['output' => ['mediatypeid'], 'filter' => ['type' => 0]]);
            return is_array($types) ? array_map('strval', array_column($types, 'mediatypeid')) : [];
        }
        return [];
    }

    /**
     * Виставляє severity-маску на налаштовані методи сповіщення користувача (усі, або лише з переліку mediatype).
     * Зберігає інші поля media (sendto/active/period) без змін.
     * @param list<string>|null $mediaTypeIds обмеження за mediatypeid; null = усі media
     */
    public function updateUserMediaSeverity(string $userId, int $severityMask, ?array $mediaTypeIds = null): bool {
        $medias = $this->getUserMediasFull($userId);
        $changed = false;
        foreach ($medias as &$media) {
            if ($mediaTypeIds === null || in_array((string)$media['mediatypeid'], $mediaTypeIds, true)) {
                $media['severity'] = (string)$severityMask;
                $changed = true;
            }
        }
        unset($media);
        if (!$changed) {
            return false;
        }
        $result = $this->request('user.update', [
            'userid' => $userId,
            'medias' => $medias,
        ]);
        return is_array($result) && isset($result['userids']);
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

    public function createUserToken($userID, int $expiresAt = 0) {
        $result = $this->request('token.create',[
            'name'=>'zbx_bot',
            'userid'=>$userID,
            'expires_at'=>$expiresAt,
        ]);
        return (is_array($result)) ? $result['tokenids']['0'] : null;
    }

    public function updateUserTokenExpiry(string $tokenId, int $expiresAt): void {
        $this->request('token.update', ['tokenid' => $tokenId, 'expires_at' => $expiresAt]);
    }

    public function generateUserToken($userID) {
        $result = $this->request('token.generate',[$userID]);
        return (is_array($result)) ? $result['0']['token'] : null;
    }

    /**
     * Створює (або оновлює термін) токен Zabbix-користувача 'zbx_bot' і генерує рядок токена.
     * @param int $expiresAt unix time; 0 - без терміну
     */
    public function issueUserToken(string $userId, int $expiresAt): ?string {
        $tokenId = $this->getUserToken($userId);
        if ($tokenId !== '') {
            $this->updateUserTokenExpiry($tokenId, $expiresAt);
        } else {
            $tokenId = $this->createUserToken($userId, $expiresAt);
        }
        if (empty($tokenId)) {
            return null;
        }
        $token = $this->generateUserToken($tokenId);
        return ($token && strlen($token) === 64) ? $token : null;
    }

    /**
     * Активні проблеми користувача (його токеном - діють його права доступу).
     * Лише ті, чиї тригери і хости ввімкнені.
     * @return list<array<string,mixed>>
     */
    public function getUserProblems(string $userToken,$severity=['5'],$groupID=NULL,$timeTill=NULL) {
        $request = [
            'output' => ['eventid','clock','name','objectid'],
            'severities' => $severity,
            'sortfield' => 'eventid',
            'sortorder' => 'DESC',
            'source' => 0,
            'object' => 0,
        ];
        if (isset($groupID)) $request['groupids'] = $groupID;
        if (isset($timeTill)) $request['time_till'] = $timeTill;
        $problems = $this->request('problem.get',$request,$userToken);
        if (!is_array($problems) || !$problems) {
            return [];
        }
        $valid = $this->request('trigger.get', [
            'output' => ['triggerid'],
            'triggerids' => array_values(array_unique(array_column($problems, 'objectid'))),
            'monitored' => true,
            'active' => true,
        ], $userToken);
        $validIds = array_flip(array_column((array)$valid, 'triggerid'));
        return array_values(array_filter($problems, fn($p) => isset($validIds[$p['objectid']])));
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
        $result = $this->request('hostgroup.get',$request,$userToken);
        return (is_array($result)) ? $result : null;
    }

    /** ID групи за назвою (без урахування регістру); мапа груп кешується (zabbix.group_cache_ttl, 3600с). */
    public function getGroupIdByName(string $groupName) {
        $ttl = (int)ConfigService::getInstance()->getNested('zabbix.group_cache_ttl', 3600);
        $map = $this->cache->get('zbx:groups', $ttl);
        if ($map === null) {
            $groups = $this->getGroups(false);
            if (!is_array($groups)) {
                $map = $this->cache->get('zbx:groups', PHP_INT_MAX) ?? [];
                return $map[mb_strtolower($groupName)] ?? null;
            }
            $map = [];
            foreach ($groups as $g) {
                $map[mb_strtolower((string)$g['name'])] = (string)$g['groupid'];
            }
            $this->cache->set('zbx:groups', $map);
        }
        return $map[mb_strtolower($groupName)] ?? null;
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

    /** @param list<string> $hosts hostid-и, що додаються в групу */
    public function massAddHostGroup($groupID,$hosts) {
        $result = $this->request('hostgroup.massadd',[
            'groups' => [
              ['groupid' => $groupID],
            ],
            'hosts' => array_map(fn($id) => ['hostid' => $id], $hosts),
        ]);
        return (is_array($result)) ? $result : null;
    }

    /**
     * Пошук хостів за частиною імені/технічної назви та, якщо запит схожий на IP, за адресою інтерфейсу.
     * Виконується токеном користувача, тож видно лише дозволені йому хости.
     * @return list<array<string,mixed>> хости з interfaces (до $limit)
     */
    public function searchHosts(string $userToken, string $query, int $limit = 11): array {
        $fields = ['output' => ['hostid', 'host', 'name', 'status'], 'selectInterfaces' => ['ip', 'dns', 'main', 'type']];
        $found = (array)$this->request('host.get', $fields + [
            'search' => ['name' => $query, 'host' => $query],
            'searchByAny' => true,
            'sortfield' => 'name',
            'limit' => $limit,
        ], $userToken);

        if (preg_match('/^[0-9.]{3,15}$/', $query) === 1) {
            $ifaces = (array)$this->request('hostinterface.get', [
                'output' => ['hostid'],
                'search' => ['ip' => $query],
                'limit' => $limit,
            ], $userToken);
            $have = array_column($found, 'hostid');
            $extra = array_values(array_diff(array_unique(array_column($ifaces, 'hostid')), $have));
            if ($extra) {
                $found = array_merge($found, (array)$this->request('host.get', $fields + [
                    'hostids' => $extra,
                    'sortfield' => 'name',
                ], $userToken));
            }
        }
        return array_slice($found, 0, $limit);
    }

    /** @return array<string,mixed>|null */
    public function hostById(string $userToken, string $hostId): ?array {
        $res = $this->request('host.get', [
            'hostids' => $hostId,
            'output' => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'dns', 'main', 'type'],
            'selectInventory' => ['tag', 'location'],
        ], $userToken);
        return is_array($res) && isset($res[0]) ? $res[0] : null;
    }

    /**
     * Активні проблеми хоста, найсерйозніші першими.
     * @return list<array<string,mixed>>
     */
    public function hostProblems(string $userToken, string $hostId, int $limit = 15): array {
        $problems = (array)$this->request('problem.get', [
            'hostids' => $hostId,
            'output' => ['eventid', 'clock', 'name', 'severity'],
            'source' => 0,
            'object' => 0,
            'sortfield' => 'eventid',
            'sortorder' => 'DESC',
            'limit' => 100,
        ], $userToken);
        usort($problems, fn($a, $b) => [(int)$b['severity'], (int)$b['eventid']] <=> [(int)$a['severity'], (int)$a['eventid']]);
        return array_slice($problems, 0, $limit);
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