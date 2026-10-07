<?php

namespace ZabbixBot\Services;

/**
 * Черга недоставлених повідомлень (плоский JSON-файл). Усі зміни - під flock, бо вебхук (додає)
 * і cron app:retry-messages (забирає) працюють одночасно.
 * Елемент - параметри sendMessage; службовий ключ "_alert" ({event_id, chat_id, mode}) позначає сповіщення Zabbix,
 * для якого після доставки треба оновити AlertStore.
 */
class MessageQueue {
    protected $queueFile;

    public function __construct($queueFile = 'message_queue.json') {
        if (!file_exists(USER_PREF_PATH)) mkdir(USER_PREF_PATH,0777,true);
        $this->queueFile = fixpath(USER_PREF_PATH).$queueFile;
    }

    public function enqueue($message) {
        $this->update(function (array $queue) use ($message) {
            $queue[] = $message;
            return $queue;
        });
    }

    public function dequeue() {
        $message = null;
        $this->update(function (array $queue) use (&$message) {
            $message = array_shift($queue);
            return $queue;
        });
        return $message;
    }

    /** Перший елемент без видалення. */
    public function peek(): ?array {
        return $this->loadQueue()[0] ?? null;
    }

    public function getQueueSize() {
        return count($this->loadQueue());
    }

    /** Чи є в черзі невідправлене сповіщення цієї події цьому чату (щоб зберегти порядок проблема -> відновлення). */
    public function hasAlert(string $eventId, string $chatId): bool {
        foreach ($this->loadQueue() as $item) {
            $a = $item['_alert'] ?? null;
            if ($a && (string)$a['event_id'] === $eventId && (string)$a['chat_id'] === $chatId) {
                return true;
            }
        }
        return false;
    }

    protected function loadQueue() {
        $raw = @file_get_contents($this->queueFile);
        return $raw === false ? [] : (json_decode($raw, true) ?: []);
    }

    /** Читання-зміна-запис під ексклюзивним блокуванням. */
    private function update(callable $fn): void {
        $fh = fopen($this->queueFile, 'c+');
        if ($fh === false) {
            return;
        }
        flock($fh, LOCK_EX);
        $queue = json_decode((string)stream_get_contents($fh), true) ?: [];
        $queue = $fn($queue);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(array_values($queue)));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
