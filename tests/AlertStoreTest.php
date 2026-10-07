<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\AlertStore;

final class AlertStoreTest extends TestCase
{
    private string $dir;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-alerts-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function store(int $ttl = 30): AlertStore
    {
        return new AlertStore($this->dir, $ttl, fn() => $this->now);
    }

    public function testPutGetDelete(): void
    {
        $s = $this->store();
        $this->assertNull($s->get('100', '55'));
        $this->assertTrue($s->put('100', '55', 777));
        $this->assertSame(777, $s->get('100', '55'));
        $this->assertNull($s->get('100', '56'), 'keyed by event AND user');
        $this->assertFileExists($this->dir.'/100_55.json');
        $s->delete('100', '55');
        $this->assertNull($s->get('100', '55'));
    }

    public function testKeysAreSanitizedAgainstPathTraversal(): void
    {
        $s = $this->store();
        $s->put('../1', '-100/../2', 5);
        $this->assertSame([], array_filter(glob($this->dir.'/*.json'), fn($f) => !preg_match('#/(msg_-?[0-9]+_[0-9]+|[0-9]+_-?[0-9]+)\.json$#', $f)));
    }

    public function testMessageIndex(): void
    {
        $s = $this->store();
        $s->put('100', '55', 777);
        $this->assertSame('100', $s->eventForMessage('55', 777), 'put() indexes the problem message');
        $this->assertNull($s->eventForMessage('56', 777), 'keyed by chat');

        $this->assertTrue($s->indexMessage('100', '55', 778));
        $this->assertSame('100', $s->eventForMessage('55', 778));
        $this->assertFalse($s->indexMessage('100', '55', 0));
        $this->assertFalse($s->indexMessage('', '55', 9));

        $s->delete('100', '55');
        $this->assertSame('100', $s->eventForMessage('55', 777), 'recovery keeps the index: resolved problems can still be commented');
    }

    public function testPurgeAlsoRemovesExpiredIndex(): void
    {
        $s = $this->store(30);
        $s->indexMessage('1', '5', 10);
        touch($this->dir.'/msg_5_10.json', time() - 31 * 86400);
        $this->assertSame(1, $s->purgeExpired());
        $this->assertNull($s->eventForMessage('5', 10));
    }

    public function testPurgeRemovesOnlyExpiredAndIsThrottled(): void
    {
        $s = $this->store(30);
        $s->put('1', '5', 1);
        touch($this->dir.'/1_5.json', time() - 31 * 86400);
        $s->put('2', '5', 2);
        $this->assertSame(1, $s->purgeExpired());
        $this->assertNull($s->get('1', '5'));
        $this->assertSame(2, $s->get('2', '5'));

        touch($this->dir.'/2_5.json', time() - 40 * 86400);
        $this->assertSame(0, $s->purgeExpired(), 'throttled: once per hour');
        touch($this->dir.'/.purged', time() - 3601);
        $this->assertSame(1, $s->purgeExpired());
    }
}
