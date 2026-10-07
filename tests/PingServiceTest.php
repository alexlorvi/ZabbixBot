<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
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
        $this->assertSame(PingService::MAX_COUNT, PingService::clampCount(500));
    }
}
