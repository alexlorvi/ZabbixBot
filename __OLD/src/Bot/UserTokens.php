<?php
declare(strict_types=1);

namespace ZbxBot\Bot;

use Psr\Log\LoggerInterface;
use ZbxBot\Storage\TokenStore;
use ZbxBot\Zabbix\ZabbixException;
use ZbxBot\Zabbix\ZabbixService;

/** Персональні токени: беремо зі сховища, а якщо немає/скоро спливе - випускаємо новий. */
class UserTokens
{
    private const RENEW_BEFORE = 86400;

    public function __construct(
        private readonly TokenStore $store,
        private readonly ZabbixService $zbx,
        private readonly int $ttlDays,
        private readonly LoggerInterface $log,
    ) {
    }

    public function tokenFor(string $chatId, array $user): ?string
    {
        $rec = $this->store->get($chatId);
        if ($rec !== null && ($rec['exp'] === null || $rec['exp'] > time() + self::RENEW_BEFORE)) {
            return $rec['token'];
        }
        return $this->issue($chatId, $user);
    }

    public function issue(string $chatId, array $user): ?string
    {
        $exp = $this->ttlDays > 0 ? time() + $this->ttlDays * 86400 : 0;
        try {
            $token = $this->zbx->issueUserToken($user['userid'], $exp);
        } catch (ZabbixException $e) {
            $this->log->error('Token issue failed: '.$e->getMessage());
            return null;
        }
        if (!$this->store->put($chatId, $token, $exp ?: null)) {
            $this->log->error('Cannot save token for chat '.$chatId);
            return null;
        }
        $this->log->info('User token issued, user '.$user['userid'].($exp ? ', expires '.date('Y-m-d', $exp) : ''));
        return $token;
    }
}
