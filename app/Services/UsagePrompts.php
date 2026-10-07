<?php

namespace ZabbixBot\Services;

/**
 * Довідки, надіслані у відповідь на порожню команду (напр. "/ping" без хоста).
 * Якщо користувач потім редагує своє повідомлення (edited_message), бот видаляє довідку і виконує виправлену команду.
 * Один запис у FileCache на чат: [id повідомлення користувача => [id довідки, час]]; старші за TTL відкидаються
 * (Telegram дозволяє боту видаляти повідомлення лише 48 год), не більше MAX записів.
 */
final class UsagePrompts
{
    public const TTL = 48 * 3600;
    private const MAX = 20;

    public function __construct(private readonly FileCache $cache)
    {
    }

    /** Інстанс на спільному кеші бота (CACHE_PATH). */
    public static function forBot(): self
    {
        return new self(new FileCache(CACHE_PATH));
    }

    public function remember(string $chatId, int $userMessageId, int $usageMessageId): void
    {
        if ($userMessageId <= 0 || $usageMessageId <= 0) {
            return;
        }
        $now = $this->cache->now();
        $this->cache->update($this->key($chatId), function ($map) use ($userMessageId, $usageMessageId, $now) {
            $map = self::fresh((array)$map, $now);
            $map[(string)$userMessageId] = [$usageMessageId, $now];
            return array_slice($map, -self::MAX, null, true);
        });
    }

    /** Id довідки до цього повідомлення (і забуває її), або null - на це повідомлення довідки не було. */
    public function take(string $chatId, int $userMessageId): ?int
    {
        $now = $this->cache->now();
        $found = null;
        $this->cache->update($this->key($chatId), function ($map) use ($userMessageId, $now, &$found) {
            $map = self::fresh((array)$map, $now);
            if (isset($map[(string)$userMessageId])) {
                $found = (int)$map[(string)$userMessageId][0];
                unset($map[(string)$userMessageId]);
            }
            return $map;
        });
        return $found;
    }

    private static function fresh(array $map, int $now): array
    {
        return array_filter($map, fn($rec) => is_array($rec) && $now - (int)($rec[1] ?? 0) < self::TTL);
    }

    private function key(string $chatId): string
    {
        return 'usage:'.$chatId;
    }
}
