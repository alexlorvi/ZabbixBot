<?php

namespace ZabbixBot\Services;

class MessageQueue {
    protected $queueFile;

    public function __construct($queueFile = 'message_queue.json') {
        if (!file_exists(USER_PREF_PATH)) mkdir(USER_PREF_PATH,0777,true);
        $this->queueFile = fixpath(USER_PREF_PATH).$queueFile;
    }

    public function enqueue($message) {
        $queue = $this->loadQueue();
        $queue[] = $message;
        $this->saveQueue($queue);
    }

    public function dequeue() {
        $queue = $this->loadQueue();
        $message = array_shift($queue);
        $this->saveQueue($queue);
        return $message;
    }

    public function getQueueSize() {
        $queue = $this->loadQueue();
        return count($queue);
    }

    protected function loadQueue() {
        if (file_exists($this->queueFile)) {
            return json_decode(file_get_contents($this->queueFile), true) ?: [];
        }
        return [];
    }

    protected function saveQueue($queue) {
        file_put_contents($this->queueFile, json_encode($queue));
    }
}
