<?php
declare(strict_types=1);

namespace ZbxBot\Zabbix;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ZbxBot\Cache\FileCache;

class ZabbixService
{
    private const TOKEN_NAME = 'zbx_bot';
    private const EVENTS_CHUNK = 200;

    public function __construct(
        private readonly ZabbixClient $client,
        private readonly FileCache $cache,
        private readonly array $config = [],
        private readonly LoggerInterface $log = new NullLogger(),
    ) {
    }

    /**
     * Користувачі Zabbix з медіа Telegram: chat id => дані. Кеш у файлі (user_cache_ttl, 300 с);
     * якщо Zabbix недоступний - віддаємо застарілий кеш. null - даних немає взагалі.
     * @return array<string,array<string,string>>|null
     */
    public function telegramUsers(): ?array
    {
        $ttl = (int)($this->config['user_cache_ttl'] ?? 300);
        $fresh = $this->cache->get('zbx:users', $ttl);
        if ($fresh !== null) {
            return $fresh;
        }
        try {
            $users = $this->client->call('user.get', [
                'output' => ['userid', 'username', 'name', 'surname'],
                'selectMedias' => ['mediatypeid', 'sendto', 'active', 'severity'],
                'mediatypeids' => (string)($this->config['zbx_mediatype_id'] ?? '16'),
            ]);
        } catch (ZabbixException $e) {
            $this->log->error('user.get failed: '.$e->getMessage());
            return $this->cache->get('zbx:users', PHP_INT_MAX);
        }
        $map = [];
        foreach ((array)$users as $user) {
            foreach ((array)($user['medias'] ?? []) as $media) {
                $sendto = trim((string)($media['sendto'] ?? ''));
                if ($sendto !== '' && !isset($map[$sendto])) {
                    $map[$sendto] = [
                        'userid' => (string)$user['userid'],
                        'username' => (string)($user['username'] ?? ''),
                        'name' => (string)($user['name'] ?? ''),
                        'surname' => (string)($user['surname'] ?? ''),
                        'severity' => (string)($media['severity'] ?? ''),
                    ];
                }
            }
        }
        $this->cache->set('zbx:users', $map);
        return $map;
    }

    /** @return array<string,string>|null */
    public function findUser(string $chatId): ?array
    {
        return ($this->telegramUsers() ?? [])[$chatId] ?? null;
    }

    public function resetUserCache(): void
    {
        $this->cache->delete('zbx:users');
        $this->cache->delete('zbx:groups');
    }

    /**
     * Активні проблеми користувача (його токеном - діють його права доступу).
     * Лише ті, чиї тригери і хости ввімкнені.
     * @return list<array<string,mixed>>
     * @throws ZabbixException
     */
    public function userProblems(string $userToken, array $severity = [5], ?int $groupId = null, ?int $timeTill = null): array
    {
        $req = [
            'output' => ['eventid', 'clock', 'name', 'objectid'],
            'severities' => $severity,
            'sortfield' => 'eventid',
            'sortorder' => 'DESC',
            'source' => 0,
            'object' => 0,
        ];
        if ($groupId !== null) {
            $req['groupids'] = $groupId;
        }
        if ($timeTill !== null) {
            $req['time_till'] = $timeTill;
        }
        $problems = $this->client->call('problem.get', $req, $userToken);
        if (!is_array($problems) || !$problems) {
            return [];
        }
        $valid = $this->client->call('trigger.get', [
            'output' => ['triggerid'],
            'triggerids' => array_values(array_unique(array_column($problems, 'objectid'))),
            'monitored' => true,
            'active' => true,
        ], $userToken);
        $validIds = array_flip(array_column((array)$valid, 'triggerid'));
        return array_values(array_filter($problems, fn($p) => isset($validIds[$p['objectid']])));
    }

    /**
     * Події пачкою (один event.get замість запиту на кожну).
     * @param list<string|int> $ids
     * @return array<string,array<string,mixed>> eventid => подія
     */
    public function events(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::EVENTS_CHUNK) as $chunk) {
            try {
                $res = $this->client->call('event.get', [
                    'output' => ['eventid', 'acknowledged', 'name', 'clock'],
                    'selectAcknowledges' => ['clock', 'message', 'username'],
                    'selectHosts' => ['host', 'name'],
                    'eventids' => $chunk,
                ]);
            } catch (ZabbixException $e) {
                $this->log->error('event.get failed: '.$e->getMessage());
                continue;
            }
            foreach ((array)$res as $ev) {
                $out[(string)$ev['eventid']] = $ev;
            }
        }
        return $out;
    }

    public function event(string $id): ?array
    {
        return $this->events([$id])[$id] ?? null;
    }

    /** ID групи за назвою (без урахування регістру); мапа груп кешується на годину. */
    public function groupId(string $name): ?int
    {
        $map = $this->cache->get('zbx:groups', 3600);
        if ($map === null) {
            try {
                $groups = $this->client->call('hostgroup.get', ['output' => ['groupid', 'name']]);
            } catch (ZabbixException $e) {
                $this->log->error('hostgroup.get failed: '.$e->getMessage());
                $map = $this->cache->get('zbx:groups', PHP_INT_MAX);
                return isset($map[mb_strtolower($name)]) ? (int)$map[mb_strtolower($name)] : null;
            }
            $map = [];
            foreach ((array)$groups as $g) {
                $map[mb_strtolower((string)$g['name'])] = (string)$g['groupid'];
            }
            $this->cache->set('zbx:groups', $map);
        }
        $id = $map[mb_strtolower($name)] ?? null;
        return $id === null ? null : (int)$id;
    }

    /**
     * Пошук хостів за частиною імені/технічної назви та, якщо запит схожий на IP, за адресою інтерфейсу.
     * Виконується токеном користувача, тож видно лише дозволені йому хости.
     * @return list<array<string,mixed>> хости з interfaces (до $limit)
     * @throws ZabbixException
     */
    public function searchHosts(string $userToken, string $query, int $limit = 11): array
    {
        $fields = ['output' => ['hostid', 'host', 'name', 'status'], 'selectInterfaces' => ['ip', 'dns', 'main', 'type']];
        $found = (array)$this->client->call('host.get', $fields + [
            'search' => ['name' => $query, 'host' => $query],
            'searchByAny' => true,
            'sortfield' => 'name',
            'limit' => $limit,
        ], $userToken);

        if (preg_match('/^[0-9.]{3,15}$/', $query) === 1) {
            $ifaces = (array)$this->client->call('hostinterface.get', [
                'output' => ['hostid'],
                'search' => ['ip' => $query],
                'limit' => $limit,
            ], $userToken);
            $have = array_column($found, 'hostid');
            $extra = array_values(array_diff(array_unique(array_column($ifaces, 'hostid')), $have));
            if ($extra) {
                $found = array_merge($found, (array)$this->client->call('host.get', $fields + [
                    'hostids' => $extra,
                    'sortfield' => 'name',
                ], $userToken));
            }
        }
        return array_slice($found, 0, $limit);
    }

    /**
     * @return array<string,mixed>|null
     * @throws ZabbixException
     */
    public function hostById(string $userToken, string $hostId): ?array
    {
        $res = $this->client->call('host.get', [
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
     * @throws ZabbixException
     */
    public function hostProblems(string $userToken, string $hostId, int $limit = 15): array
    {
        $problems = (array)$this->client->call('problem.get', [
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

    /**
     * @return list<array<string,mixed>>
     * @throws ZabbixException
     */
    public function hostsByGroup(int $groupId): array
    {
        return (array)$this->client->call('host.get', [
            'groupids' => $groupId,
            'output' => ['hostid', 'host', 'status', 'name'],
            'selectInventory' => ['tag'],
        ]);
    }

    /** @param list<string> $hostIds @throws ZabbixException */
    public function removeHostsFromGroup(int $groupId, array $hostIds): void
    {
        $this->client->call('hostgroup.massremove', ['groupids' => $groupId, 'hostids' => $hostIds]);
    }

    /** @param list<string> $hostIds @throws ZabbixException */
    public function addHostsToGroup(int $groupId, array $hostIds): void
    {
        $this->client->call('hostgroup.massadd', [
            'groups' => [['groupid' => $groupId]],
            'hosts' => array_map(fn($id) => ['hostid' => $id], $hostIds),
        ]);
    }

    /**
     * Створює (або оновлює термін) токен користувача 'zbx_bot' і генерує новий рядок токена.
     * @param int $expiresAt unix time; 0 - без терміну
     * @throws ZabbixException
     */
    public function issueUserToken(string $userId, int $expiresAt): string
    {
        $existing = $this->client->call('token.get', ['filter' => ['name' => self::TOKEN_NAME], 'userids' => $userId]);
        if (is_array($existing) && isset($existing[0]['tokenid'])) {
            $tokenId = (string)$existing[0]['tokenid'];
            $this->client->call('token.update', ['tokenid' => $tokenId, 'expires_at' => $expiresAt]);
        } else {
            $created = $this->client->call('token.create', [
                'name' => self::TOKEN_NAME,
                'userid' => $userId,
                'expires_at' => $expiresAt,
            ]);
            $tokenId = (string)($created['tokenids'][0] ?? '');
        }
        $res = $tokenId === '' ? null : $this->client->call('token.generate', [$tokenId]);
        $token = (string)($res[0]['token'] ?? '');
        if (strlen($token) !== 64) {
            throw new ZabbixException('token.generate: unexpected response');
        }
        return $token;
    }
}
