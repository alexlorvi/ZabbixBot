<?php
declare(strict_types=1);

namespace ZbxBot\Tests;

use PHPUnit\Framework\TestCase;
use ZbxBot\Cache\FileCache;
use ZbxBot\Cache\RateLimiter;
use ZbxBot\Cache\UpdateDeduplicator;

final class FileCacheTest extends TestCase
{
    private string $dir;
    private int $now = 1000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    private function cache(): FileCache
    {
        return new FileCache($this->dir, fn() => $this->now);
    }

    public function testTtlAndStale(): void
    {
        $c = $this->cache();
        $c->set('k', ['a' => 1]);
        $this->assertSame(['a' => 1], $c->get('k', 60));
        $this->now += 61;
        $this->assertNull($c->get('k', 60));
        $this->assertSame(['a' => 1], $c->get('k', PHP_INT_MAX));
        $this->assertNull($c->get('missing', 60));
    }

    public function testFilePermissions(): void
    {
        $c = $this->cache();
        $c->set('k', 1);
        $files = glob($this->dir.'/*.json');
        $this->assertCount(1, $files);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
    }

    public function testRateLimiterSlidingWindow(): void
    {
        $rl = new RateLimiter($this->cache());
        $this->assertTrue($rl->allow('u', 2, 60));
        $this->assertTrue($rl->allow('u', 2, 60));
        $this->assertFalse($rl->allow('u', 2, 60));
        $this->assertTrue($rl->allow('other', 2, 60));
        $this->now += 61;
        $this->assertTrue($rl->allow('u', 2, 60));
    }

    public function testDeduplicator(): void
    {
        $d = new UpdateDeduplicator($this->cache());
        $this->assertFalse($d->seen(10));
        $this->assertFalse($d->seen(9)); // доставка не обов'язково по порядку
        $this->assertTrue($d->seen(10));
        $this->assertTrue($d->seen(9));
    }
}
