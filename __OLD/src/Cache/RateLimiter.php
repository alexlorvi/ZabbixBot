<?php
declare(strict_types=1);

namespace ZbxBot\Cache;

/** Ковзне вікно: не більше $max дозволених звернень за $window секунд на ключ. */
final class RateLimiter
{
    public function __construct(private readonly FileCache $cache)
    {
    }

    public function allow(string $bucket, int $max, int $window): bool
    {
        $now = $this->cache->now();
        $allowed = false;
        $this->cache->update('rl:'.$bucket, function ($hits) use ($now, $max, $window, &$allowed) {
            $hits = array_values(array_filter((array)$hits, fn($t) => $t > $now - $window));
            if (count($hits) < $max) {
                $hits[] = $now;
                $allowed = true;
            }
            return $hits;
        });
        return $allowed;
    }
}
