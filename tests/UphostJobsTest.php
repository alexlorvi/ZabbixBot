<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\UphostJobs;

final class UphostJobsTest extends TestCase
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

    public function testSlots(): void
    {
        $c = new FileCache($this->dir, fn() => $this->now);
        $this->assertSame('ok', UphostJobs::tryAdd($c, '1', '10.0.0.1', 600, 2));
        $this->assertSame('busy', UphostJobs::tryAdd($c, '1', '10.0.0.1', 600, 2));
        $this->assertSame('busy', UphostJobs::tryAdd($c, '1', 'HOST.example', 600, 2) === 'ok' ? UphostJobs::tryAdd($c, '1', 'host.EXAMPLE', 600, 2) : 'x');
        $this->assertSame('limit', UphostJobs::tryAdd($c, '1', '10.0.0.3', 600, 2));
        $this->assertSame('ok', UphostJobs::tryAdd($c, '2', '10.0.0.1', 600, 2), 'other chats are independent');

        $key = UphostJobs::key('10.0.0.1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $key);
        UphostJobs::attach($c, '1', $key, 4242, 77);
        $this->assertSame(['host' => '10.0.0.1', 'until' => 1600, 'pid' => 4242, 'notice' => 77], UphostJobs::get($c, '1', $key));

        UphostJobs::remove($c, '1', $key);
        $this->assertNull(UphostJobs::get($c, '1', $key));
        $this->assertSame('ok', UphostJobs::tryAdd($c, '1', '10.0.0.3', 600, 2));

        // прострочені слоти (job упав) звільняються самі
        $this->now += 700;
        $this->assertSame('ok', UphostJobs::tryAdd($c, '1', '10.0.0.4', 600, 1));
        $this->assertNull(UphostJobs::get($c, '2', $key));
    }
}
