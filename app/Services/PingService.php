<?php

namespace ZabbixBot\Services;

class PingService {
    public const MAX_COUNT = 50;
    public const DEFAULT_COUNT = 4;

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

    /** Кількість пакетів у межах 1..MAX_COUNT; порожнє/0 - DEFAULT_COUNT. */
    public static function clampCount($count): int {
        $count = (int)$count;
        if ($count < 1) {
            return self::DEFAULT_COUNT;
        }
        return min($count, self::MAX_COUNT);
    }

    public function ping($host,$count,callable $callback) {
        if (!self::validHost((string)$host)) {
            $callback("Invalid host");
            return;
        }
        $command = 'ping -O -c '.self::clampCount($count).' ' . escapeshellarg($host);
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
}
