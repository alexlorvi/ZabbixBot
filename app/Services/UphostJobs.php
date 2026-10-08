<?php

namespace ZabbixBot\Services;

/**
 * Слоти фонових /uphost одного чату (FileCache "uphost:<chatId>"): по одному на хост, не більше MAX_JOBS.
 * Запис: ключ хоста => {host, until, pid?, notice?}. Ключ (а не сам хост) - у callback_data кнопки "Скасувати":
 * ім'я хоста може не влізти в 64 байти.
 */
final class UphostJobs
{
    public const MAX_JOBS = 5;

    public static function key(string $host): string
    {
        return substr(sha1(strtolower($host)), 0, 10);
    }

    /**
     * Займає слот хоста на $seconds.
     * @return string ok | busy (на цей хост уже чекаємо) | limit (у чаті вже MAX_JOBS)
     */
    public static function tryAdd(FileCache $cache, string $chatId, string $host, int $seconds, int $max = self::MAX_JOBS): string
    {
        $now = $cache->now();
        $result = 'ok';
        $cache->update('uphost:'.$chatId, function ($jobs) use ($now, $host, $seconds, $max, &$result) {
            $jobs = self::active($jobs, $now);
            $key = self::key($host);
            if (isset($jobs[$key])) {
                $result = 'busy';
            } elseif (count($jobs) >= $max) {
                $result = 'limit';
            } else {
                $jobs[$key] = ['host' => $host, 'until' => $now + $seconds];
            }
            return $jobs;
        });
        return $result;
    }

    /** Запам'ятати PID (група процесів, setsid) і повідомлення "чекаю" з кнопкою "Скасувати". */
    public static function attach(FileCache $cache, string $chatId, string $key, int $pid, int $noticeId): void
    {
        $cache->update('uphost:'.$chatId, function ($jobs) use ($key, $pid, $noticeId) {
            $jobs = (array)$jobs;
            if (isset($jobs[$key])) {
                $jobs[$key] += ['pid' => $pid, 'notice' => $noticeId];
            }
            return $jobs;
        });
    }

    /** @return array{host:string,until:int,pid?:int,notice?:int}|null */
    public static function get(FileCache $cache, string $chatId, string $key): ?array
    {
        return self::active($cache->get('uphost:'.$chatId, PHP_INT_MAX), $cache->now())[$key] ?? null;
    }

    public static function remove(FileCache $cache, string $chatId, string $key): void
    {
        $cache->update('uphost:'.$chatId, function ($jobs) use ($key) {
            $jobs = (array)$jobs;
            unset($jobs[$key]);
            return $jobs;
        });
    }

    /** Лише незавершені за часом (завислі після падіння job-а слоти звільняються самі). */
    private static function active($jobs, int $now): array
    {
        return array_filter(is_array($jobs) ? $jobs : [], fn($j) => is_array($j) && (int)($j['until'] ?? 0) > $now);
    }
}
