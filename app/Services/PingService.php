<?php

namespace ZabbixBot\Services;

/**
 * Пінг з бота. До LIVE_MAX пакетів - живий вивід (кожен рядок редагує повідомлення), більше - фоновий
 * app:ping-job з ping -q, який шле лише підсумок.
 */
class PingService {
    public const DEFAULT_COUNT = 4;
    /** Межа живого виводу: понад неї пінг іде у фон, користувач отримує лише підсумок. */
    public const LIVE_MAX = 100;
    /** Верхня межа за замовчуванням (net.ping_max_count) - від випадкового /ping host 99999999. */
    public const MAX_COUNT = 10000;

    /**
     * IP або ім'я хоста. Лише ці символи і не з "-" на початку: escapeshellarg() не рятує від того,
     * що ping сприйме "-f"/"--help" як свій параметр.
     */
    public static function validHost(string $host): bool {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        return strlen($host) <= 253
            && preg_match('/^[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,62})(?:\.[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,62}))*\.?$/', $host) === 1;
    }

    /** Кількість пакетів у межах 1..$max; порожнє/0 - DEFAULT_COUNT. */
    public static function clampCount($count, int $max = self::MAX_COUNT): int {
        $count = (int)$count;
        if ($count < 1) {
            return self::DEFAULT_COUNT;
        }
        return min($count, max(1, $max));
    }

    public static function isLive(int $count): bool {
        return $count <= self::LIVE_MAX;
    }

    public function ping($host,$count,callable $callback) {
        if (!self::validHost((string)$host)) {
            $callback("Invalid host");
            return;
        }
        $command = 'ping -O -c '.(int)$count.' ' . escapeshellarg($host);
        $descriptorspec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($command, $descriptorspec, $pipes);
        if (is_resource($process)) {
            while ($line = fgets($pipes[1])) {
                $callback($line);
            }
            fclose($pipes[1]);
            $return_value = proc_close($process);

            if ($return_value !== 0) {
                $callback("Ping command failed!");
            }
        }
    }

    /**
     * Тихий пінг (ping -q): лише статистика.
     * @return array{ok:bool,text:string} ok - були відповіді (код ping 0)
     */
    public function summary(string $host, int $count): array {
        if (!self::validHost($host)) {
            return ['ok' => false, 'text' => 'Invalid host'];
        }
        exec('ping -q -c '.$count.' '.escapeshellarg($host).' 2>&1', $out, $rc);
        return ['ok' => $rc === 0, 'text' => self::statistics($out)];
    }

    /** Чиста функція: з виводу ping -q - блок статистики (від рядка "--- ... ---"), або весь вивід, якщо його немає. */
    public static function statistics(array $lines): string {
        foreach ($lines as $i => $line) {
            if (str_starts_with(trim($line), '---')) {
                return trim(implode("\n", array_slice($lines, $i)));
            }
        }
        return trim(implode("\n", $lines));
    }

    /**
     * Чи можна запустити фоновий пінг у цьому чаті (один одночасно); займає слот на $seconds.
     * Слот: {until, pid?, notice?, host?} - дані для кнопки "Скасувати" (attachJob()).
     */
    public static function tryLock(FileCache $cache, string $chatId, int $seconds): bool {
        $now = $cache->now();
        $got = false;
        $cache->update('pingjob:'.$chatId, function ($job) use ($now, $seconds, &$got) {
            if (self::active($job, $now)) {
                return $job;
            }
            $got = true;
            return ['until' => $now + $seconds];
        });
        return $got;
    }

    /** Запам'ятати запущене завдання (PID = група процесів, setsid) і повідомлення "взято в роботу". */
    public static function attachJob(FileCache $cache, string $chatId, int $pid, int $noticeId, string $host): void {
        $cache->update('pingjob:'.$chatId, fn($job) => ['pid' => $pid, 'notice' => $noticeId, 'host' => $host] + (array)$job);
    }

    /** @return array{until:int,pid?:int,notice?:int,host?:string}|null активне фонове завдання чату */
    public static function job(FileCache $cache, string $chatId): ?array {
        $job = $cache->get('pingjob:'.$chatId, PHP_INT_MAX);
        return self::active($job, $cache->now()) ? $job : null;
    }

    public static function unlock(FileCache $cache, string $chatId): void {
        $cache->delete('pingjob:'.$chatId);
    }

    private static function active($job, int $now): bool {
        return is_array($job) && (int)($job['until'] ?? 0) > $now;
    }

    /** Чи PID - це ще наш app:ping-job для цього чату (PID-и перевикористовуються). */
    public static function isOurJob(int $pid, string $chatId): bool {
        $cmdline = @file_get_contents('/proc/'.$pid.'/cmdline');
        if ($pid <= 0 || $cmdline === false) {
            return false;
        }
        $args = explode("\0", rtrim($cmdline, "\0"));
        $i = array_search('app:ping-job', $args, true);
        return $i !== false && ($args[$i + 1] ?? null) === $chatId;
    }

    /** Зупиняє завдання разом з дочірнім ping (уся група процесів, запуск через setsid). */
    public static function killJob(int $pid): bool {
        if ($pid <= 1) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill(-$pid, 15); // від'ємний PID - уся група, 15 = SIGTERM
        }
        // /bin/sh буває dash, чий вбудований kill не розуміє "--"; procps kill - розуміє
        exec('/bin/kill -TERM -- -'.$pid.' 2>/dev/null', $out, $rc);
        return $rc === 0;
    }
}
