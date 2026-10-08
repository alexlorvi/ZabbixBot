<?php

namespace ZabbixBot\Services;

/**
 * Відповідність "подія Zabbix + користувач" -> надіслані сповіщення.
 * Один файл на пару: <dir>/<eventId>_<chatId>.json, всередині
 * {"message_id":<проблема>|null,"sent_at":..,"messages":[[id,sent_at],...],"recovered_at"?:..}.
 * message_id - щоб відновлення/оновлення йшло відповіддю на сповіщення про проблему; messages - усі сповіщення
 * події (проблема, оновлення, відновлення) для автовидалення (app:alert-cleanup); recovered_at - коли прийшло
 * відновлення (запис після цього не видаляється одразу, а чекає на app:alert-cleanup).
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
        $now = ($this->clock)();
        $ok = $this->write($this->path($eventId, $chatId), ['message_id' => $messageId, 'sent_at' => $now, 'messages' => [[$messageId, $now]]]);
        return $this->indexMessage($eventId, $chatId, $messageId) && $ok;
    }

    /**
     * Ще одне сповіщення події (оновлення чи відновлення): індекс + список повідомлень запису для автовидалення.
     * Запису немає (проблема не дійшла чи надіслана до оновлення бота) - створюється без message_id.
     * $recovered - це відновлення: запис позначається recovered_at.
     */
    public function addAlertMessage(string $eventId, string $chatId, int $messageId, bool $recovered = false): bool
    {
        if (!$this->indexMessage($eventId, $chatId, $messageId)) {
            return false;
        }
        $now = ($this->clock)();
        $rec = $this->read($this->path($eventId, $chatId)) ?? ['message_id' => null, 'sent_at' => $now, 'messages' => []];
        $rec['messages'][] = [$messageId, $now];
        if ($recovered) {
            $rec['recovered_at'] = $now;
        }
        return $this->write($this->path($eventId, $chatId), $rec);
    }

    /**
     * Записи, відновлені не пізніше $before (для app:alert-cleanup).
     * @return list<array{event_id:string,chat_id:string,messages:list<array{0:int,1:int}>,recovered_at:int}>
     */
    public function recoveredBefore(int $before): array
    {
        $out = [];
        foreach (glob($this->dir.'/*_*.json') ?: [] as $file) {
            if (!preg_match('/^([0-9]+)_(-?[0-9]+)\.json$/', basename($file), $m)) {
                continue; // msg_<chat>_<id>.json - зворотний індекс
            }
            $rec = $this->read($file);
            if (isset($rec['recovered_at']) && (int)$rec['recovered_at'] <= $before) {
                $out[] = ['event_id' => $m[1], 'chat_id' => $m[2], 'messages' => (array)($rec['messages'] ?? []), 'recovered_at' => (int)$rec['recovered_at']];
            }
        }
        return $out;
    }

    /** Прибрати зворотний індекс повідомлення (воно видалене з чату). */
    public function forgetMessage(string $chatId, int $messageId): void
    {
        @unlink($this->messagePath($chatId, $messageId));
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

    /** message_id сповіщення про проблему (ціль для відповіді) або null. */
    public function get(string $eventId, string $chatId): ?int
    {
        $rec = $this->read($this->path($eventId, $chatId));
        return isset($rec['message_id']) ? (int)$rec['message_id'] : null;
    }

    private function read(string $file): ?array
    {
        $raw = @file_get_contents($file);
        $rec = $raw === false ? null : json_decode($raw, true);
        return is_array($rec) ? $rec : null;
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
