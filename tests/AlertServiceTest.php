<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\AlertService;
use ZabbixBot\Services\AlertStore;

final class AlertServiceTest extends TestCase
{
    private string $dir;
    /** @var list<array{0:string,1:string,2:array}> */
    private array $sent = [];
    private ?int $nextId = 500;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-alertsvc-'.bin2hex(random_bytes(4));
        $this->sent = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function service(bool $known = true, bool $require = true): AlertService
    {
        return new AlertService(
            new AlertStore($this->dir),
            function (string $chat, string $text, array $opt): ?int {
                $this->sent[] = [$chat, $text, $opt];
                return $this->nextId === null ? null : $this->nextId++;
            },
            fn(string $chat): bool => $known,
            $require,
        );
    }

    private function payload(array $over = []): array
    {
        return $over + ['sendto' => '42', 'subject' => 'Problem: Door', 'message' => 'Host H', 'event_id' => '900', 'event_value' => '1'];
    }

    public function testProblemIsSentAndRemembered(): void
    {
        $r = $this->service()->handle($this->payload());
        $this->assertTrue($r['ok']);
        $this->assertSame(500, $r['message_id']);
        $this->assertSame("Problem: Door\nHost H", $this->sent[0][1]);
        $this->assertArrayNotHasKey('reply_parameters', $this->sent[0][2]);
        $this->assertTrue($this->sent[0][2]['keep_keyboard']);
        $this->assertFileExists($this->dir.'/900_42.json');
    }

    public function testRecoveryRepliesToProblemAndForgetsIt(): void
    {
        $svc = $this->service();
        $svc->handle($this->payload());
        $r = $svc->handle($this->payload(['subject' => 'Resolved: Door', 'event_value' => '0']));
        $this->assertSame('recovery+reply', $r['mode']);
        $this->assertSame(500, $this->sent[1][2]['reply_parameters']['message_id']);
        $this->assertTrue($this->sent[1][2]['reply_parameters']['allow_sending_without_reply']);
        $this->assertFileDoesNotExist($this->dir.'/900_42.json');
    }

    public function testRecoveryWithoutKnownProblemIsPlainSend(): void
    {
        $r = $this->service()->handle($this->payload(['event_value' => '0']));
        $this->assertTrue($r['ok']);
        $this->assertSame('recovery', $r['mode']);
        $this->assertArrayNotHasKey('reply_parameters', $this->sent[0][2]);
    }

    public function testUpdateRepliesButKeepsRecord(): void
    {
        $svc = $this->service();
        $svc->handle($this->payload());
        $svc->handle($this->payload(['event_update_status' => '1', 'subject' => 'Updated']));
        $this->assertSame(500, $this->sent[1][2]['reply_parameters']['message_id']);
        $this->assertFileExists($this->dir.'/900_42.json');
    }

    public function testRecordsAreSeparatePerUser(): void
    {
        $svc = $this->service();
        $svc->handle($this->payload(['sendto' => '1']));
        $svc->handle($this->payload(['sendto' => '2']));
        $svc->handle($this->payload(['sendto' => '2', 'event_value' => '0']));
        $this->assertSame(501, $this->sent[2][2]['reply_parameters']['message_id']);
        $this->assertFileExists($this->dir.'/900_1.json');
    }

    public function testFailedSendDoesNotStoreRecord(): void
    {
        $this->nextId = null;
        $r = $this->service()->handle($this->payload());
        $this->assertTrue($r['ok']);
        $this->assertNull($r['message_id']);
        $this->assertFileDoesNotExist($this->dir.'/900_42.json');
    }

    public function testValidation(): void
    {
        $this->assertSame(400, $this->service()->handle($this->payload(['sendto' => 'abc']))['status']);
        $this->assertSame(400, $this->service()->handle($this->payload(['subject' => '', 'message' => '']))['status']);
        $this->assertSame(403, $this->service(false)->handle($this->payload())['status']);
        $this->assertTrue($this->service(false, false)->handle($this->payload())['ok']);
        $this->assertSame([], array_slice($this->sent, 0, 0));
    }

    public function testNoEventIdMeansPlainSendWithoutTracking(): void
    {
        $r = $this->service()->handle($this->payload(['event_id' => '']));
        $this->assertTrue($r['ok']);
        $this->assertSame([], glob($this->dir.'/*.json') ?: []);
    }

    public function testParseModeWhitelist(): void
    {
        $this->service()->handle($this->payload(['parse_mode' => 'HTML']));
        $this->service()->handle($this->payload(['parse_mode' => 'evil']));
        $this->assertSame('html', $this->sent[0][2]['parse_mode']);
        $this->assertArrayNotHasKey('parse_mode', $this->sent[1][2]);
    }
}
