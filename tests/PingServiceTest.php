<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\PingService;

final class PingServiceTest extends TestCase
{
    public function testValidHosts(): void
    {
        foreach (['8.8.8.8', '10.16.11.5', '::1', 'google.com', 'host-01.corp.local', 'srv_01', 'localhost.'] as $ok) {
            $this->assertTrue(PingService::validHost($ok), $ok);
        }
    }

    public function testOptionsAndShellAreRejected(): void
    {
        foreach (['-f', '--help', '-i0.01', '8.8.8.8;id', '$(id)', 'a b', '', '.', 'host..com', "a\nb", str_repeat('a', 254)] as $bad) {
            $this->assertFalse(PingService::validHost($bad), var_export($bad, true));
        }
    }

    public function testClampCount(): void
    {
        $this->assertSame(PingService::DEFAULT_COUNT, PingService::clampCount(0));
        $this->assertSame(PingService::DEFAULT_COUNT, PingService::clampCount(null));
        $this->assertSame(PingService::DEFAULT_COUNT, PingService::clampCount('-3'));
        $this->assertSame(1, PingService::clampCount('1'));
        $this->assertSame(500, PingService::clampCount(500), 'any count up to the configured max');
        $this->assertSame(PingService::MAX_COUNT, PingService::clampCount(PHP_INT_MAX));
        $this->assertSame(200, PingService::clampCount(1000, 200));
    }

    public function testLiveThreshold(): void
    {
        $this->assertTrue(PingService::isLive(100));
        $this->assertFalse(PingService::isLive(101));
    }

    public function testStatisticsFromQuietPing(): void
    {
        $out = [
            'PING 8.8.8.8 (8.8.8.8) 56(84) bytes of data.',
            '',
            '--- 8.8.8.8 ping statistics ---',
            '200 packets transmitted, 199 received, 0.5% packet loss, time 199250ms',
            'rtt min/avg/max/mdev = 9.1/10.2/30.5/1.3 ms',
        ];
        $this->assertSame("--- 8.8.8.8 ping statistics ---\n200 packets transmitted, 199 received, 0.5% packet loss, time 199250ms\nrtt min/avg/max/mdev = 9.1/10.2/30.5/1.3 ms", PingService::statistics($out));
        $this->assertSame('ping: unknown host', PingService::statistics(['ping: unknown host']), 'no statistics - whole output');
    }

    public function testOneBackgroundJobPerChat(): void
    {
        $dir = sys_get_temp_dir().'/zbxbot-pinglock-'.bin2hex(random_bytes(4));
        $now = 1000;
        $cache = new FileCache($dir, function () use (&$now) { return $now; });
        $this->assertTrue(PingService::tryLock($cache, '42', 60));
        $this->assertFalse(PingService::tryLock($cache, '42', 60), 'busy');
        $this->assertTrue(PingService::tryLock($cache, '43', 60), 'other chat');
        $now += 61;
        $this->assertTrue(PingService::tryLock($cache, '42', 60), 'stale lock expires');
        PingService::unlock($cache, '42');
        $this->assertTrue(PingService::tryLock($cache, '42', 60), 'unlocked by the finished job');
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
}
