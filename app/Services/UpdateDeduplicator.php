<?php

namespace ZabbixBot\Services;

/** Telegram повторює update, якщо вебхук не відповів вчасно. Пам'ятаємо останні id (а не max: доставка може бути паралельною). */
final class UpdateDeduplicator
{
    private const KEEP = 500;

    public function __construct(private readonly FileCache $cache)
    {
    }

    /** true - update уже обробляли (або обробляється). */
    public function seen(int $updateId): bool
    {
        $dup = false;
        $this->cache->update('seen_updates', function ($ids) use ($updateId, &$dup) {
            $ids = (array)$ids;
            if (in_array($updateId, $ids, true)) {
                $dup = true;
                return $ids;
            }
            $ids[] = $updateId;
            return array_slice($ids, -self::KEEP);
        });
        return $dup;
    }
}
