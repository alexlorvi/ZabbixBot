<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use ZabbixBot\Services\AlertStore;
use ZabbixBot\Services\MessageQueue;
use ZabbixBot\Services\MessageService;

require_once __DIR__.'/stubs.php';

final class MessageServiceRetryTest extends TestCase
{
    private string $dir;
    private bool $online = false;
    private int $nextId = 700;
    /** @var list<array> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-retry-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        @unlink(USER_PREF_PATH.'/message_queue.json');
        $this->online = false;
        $this->sent = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        @unlink(USER_PREF_PATH.'/message_queue.json');
    }

    private function service(): MessageService
    {
        $api = $this->getMockBuilder(Api::class)->disableOriginalConstructor()->onlyMethods(['sendMessage', 'sendChatAction'])->getMock();
        // офлайн падає і sendChatAction (його виклик іде першим) - повідомлення все одно має потрапити в чергу
        $api->method('sendChatAction')->willReturnCallback(fn() => $this->online ? true : throw new \RuntimeException('network down'));
        $api->method('sendMessage')->willReturnCallback(function (array $p) {
            if (!$this->online) {
                throw new \RuntimeException('network down');
            }
            $this->sent[] = $p;
            return new Message(['message_id' => $this->nextId++]);
        });
        return new MessageService($api, new AlertStore($this->dir));
    }

    private function alertOpts(string $mode, string $event = '900', array $extra = []): array
    {
        return $extra + ['keep_keyboard' => true, 'alert' => ['event_id' => $event, 'chat_id' => '42', 'mode' => $mode]];
    }

    public function testQueuedProblemIsRecordedAfterRetryDelivery(): void
    {
        $svc = $this->service();
        $this->assertNull($svc->sendMessage('42', 'Problem', $this->alertOpts('problem')));
        $this->assertSame(1, $svc->getMessageQueueSize());

        $this->online = true;
        $this->assertSame(1, $svc->retryMessages());
        $this->assertSame(0, $svc->getMessageQueueSize());
        $this->assertSame(700, (new AlertStore($this->dir))->get('900', '42'));
        $this->assertArrayNotHasKey('_alert', $this->sent[0]);
    }

    public function testRecoveryQueuedBehindProblemRepliesAndKeepsOrder(): void
    {
        $svc = $this->service();
        // обидва потрапляють у чергу, поки Telegram недоступний
        $svc->sendMessage('42', 'Problem', $this->alertOpts('problem'));
        $svc->sendMessage('42', 'Resolved', $this->alertOpts('recovery'));
        $this->assertSame(2, $svc->getMessageQueueSize());

        // Telegram повернувся, але в черзі ще є проблема цієї події: нове сповіщення не має її обганяти
        $this->online = true;
        $svc->sendMessage('42', 'Resolved again', $this->alertOpts('recovery'));
        $this->assertSame([], $this->sent);
        $this->assertSame(3, $svc->getMessageQueueSize());

        $svc->retryMessages();
        $this->assertSame(['Problem', 'Resolved', 'Resolved again'], array_column($this->sent, 'text'));
        $this->assertArrayNotHasKey('reply_parameters', $this->sent[0]);
        $this->assertSame(700, json_decode($this->sent[1]['reply_parameters'], true)['message_id'], 'reply resolved at delivery time');
        $this->assertNull((new AlertStore($this->dir))->get('900', '42'), 'recovery forgets the record');
    }

    public function testRetryStopsOnTemporaryFailureAndKeepsQueue(): void
    {
        $svc = $this->service();
        $svc->sendMessage('42', 'A', ['keep_keyboard' => true]);
        $svc->sendMessage('42', 'B', ['keep_keyboard' => true]);
        $this->assertSame(0, $svc->retryMessages()); // все ще офлайн
        $this->assertSame(2, $svc->getMessageQueueSize());
    }

    public function testRetryRespectsLimit(): void
    {
        $svc = $this->service();
        foreach (['A', 'B', 'C'] as $t) {
            $svc->sendMessage('42', $t, ['keep_keyboard' => true]);
        }
        $this->online = true;
        $this->assertSame(2, $svc->retryMessages(2));
        $this->assertSame(1, $svc->getMessageQueueSize());
    }
}
