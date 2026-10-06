<?php

namespace ZabbixBot\Services;

/** Персональні токени: беремо зі сховища, а якщо немає/скоро спливе - випускаємо новий. */
class UserTokens
{
    private const RENEW_BEFORE = 86400;

    public function __construct(
        private readonly TokenStore $store,
        private readonly ZabbixService $zbx,
        private readonly int $ttlDays,
    ) {
    }

    /** @param array{userid:string} $user дані Zabbix-користувача (мінімум userid) */
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
        $token = $this->zbx->issueUserToken($user['userid'], $exp);
        if ($token === null) {
            userLOG($chatId,'error','Token issue failed for Zabbix user #'.$user['userid']);
            return null;
        }
        if (!$this->store->put($chatId, $token, $exp ?: null)) {
            userLOG($chatId,'error','Cannot save token for chat '.$chatId);
            return null;
        }
        userLOG($chatId,'info','User token issued, zabbix user #'.$user['userid'].($exp ? ', expires '.date('Y-m-d', $exp) : ''));
        return $token;
    }
}
