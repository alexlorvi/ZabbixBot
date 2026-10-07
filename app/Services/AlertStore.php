<?php

namespace ZabbixBot\Services;

/**
 * Відповідність "подія Zabbix + користувач" -> message_id надісланого сповіщення.
 * Один файл на пару: <dir>/<eventId>_<chatId>.json, всередині {"message_id":..,"sent_at":..}.
 * Потрібно, щоб сповіщення про відновлення йшло відповіддю на сповіщення про проблему.
 *
 * Зворотний індекс <dir>/msg_<chatId>_<messageId>.json -> {"event_id":..} - для квитування відповіддю на будь-яке
 * сповіщення події (проблема/оновлення/відновлення). Не видаляється при відновленні (квитувати можна й закриту
 * проблему), лише за ttl_days.
 */
class AlertStore
{
    /** @var callable */
    private $clock;

    public function __construct(private readonly string $dir, private readonly int $ttlDays = 30, ?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
    }

    /** Запам'ятати сповіщення про проблему (для відповіді відновленням) і проіндексувати його message_id. */
    public function put(string $eventId, string $chatId, int $messageId): bool
    {
        $ok = $this->write($this->path($eventId, $chatId), ['message_id' => $messageId, 'sent_at' => ($this->clock)()]);
        return $this->indexMessage($eventId, $chatId, $messageId) && $ok;
    }

    /** Лише зворотний індекс message_id -> подія (оновлення/відновлення, повідомлення /ev<id>). */
    public function indexMessage(string $eventId, string $chatId, int $messageId): bool
    {
        if ($messageId <= 0 || preg_replace('/[^0-9]/', '', $eventId) === '') {
            return false;
        }
        return $this->write($this->messagePath($chatId, $messageId), ['event_id' => $eventId, 'sent_at' => ($this->clock)()]);
    }

    /** Подія, до якої належить повідомлення бота в цьому чаті, або null. */
    public function eventForMessage(string $chatId, int $messageId): ?string
    {
        $raw = @file_get_contents($this->messagePath($chatId, $messageId));
        $rec = $raw === false ? null : json_decode($raw, true);
        return is_array($rec) && isset($rec['event_id']) ? (string)$rec['event_id'] : null;
    }

    public function get(string $eventId, string $chatId): ?int
    {
        $raw = @file_get_contents($this->path($eventId, $chatId));
        $rec = $raw === false ? null : json_decode($raw, true);
        return is_array($rec) && isset($rec['message_id']) ? (int)$rec['message_id'] : null;
    }

    public function delete(string $eventId, string $chatId): void
    {
        @unlink($this->path($eventId, $chatId));
    }

    /** Видаляє записи старші за ttl_days (подія могла так і не відновитись). Не частіше за раз на годину. @return int скільки видалено */
    public function purgeExpired(): int
    {
        $marker = $this->dir.'/.purged';
        $now = time(); // mtime файлів - реальний час, тому без ін'єкції годинника
        if ($this->ttlDays <= 0 || (is_file($marker) && $now - (int)@filemtime($marker) < 3600)) {
            return 0;
        }
        @touch($marker);
        $removed = 0;
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if ($now - (int)@filemtime($file) > $this->ttlDays * 86400 && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function write(string $file, array $data): bool
    {
        $tmp = $file.'.'.getmypid().'.tmp';
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function messagePath(string $chatId, int $messageId): string
    {
        return $this->dir.'/msg_'.preg_replace('/[^0-9-]/', '', $chatId).'_'.$messageId.'.json';
    }

    private function path(string $eventId, string $chatId): string
    {
        return $this->dir.'/'.preg_replace('/[^0-9]/', '', $eventId).'_'.preg_replace('/[^0-9-]/', '', $chatId).'.json';
    }
}
