<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\ZabbixService;

final class ZabbixMediaSeverityTest extends TestCase
{
    private function medias(): array
    {
        return [
            ['mediaid' => '58', 'mediatypeid' => '1', 'sendto' => ['a@x.ua'], 'active' => '0', 'severity' => '48', 'period' => '1-7,00:00-24:00', 'provisioned' => '0'],
            ['mediaid' => '59', 'mediatypeid' => '16', 'sendto' => '123', 'active' => '1', 'severity' => '32', 'period' => '1-5,09:00-18:00', 'provisioned' => '0'],
            ['mediaid' => '60', 'mediatypeid' => '1', 'sendto' => ['ldap@x.ua'], 'active' => '0', 'severity' => '63', 'period' => '1-7,00:00-24:00', 'provisioned' => '1'],
        ];
    }

    public function testOnlyTargetSeverityChangesOthersKept(): void
    {
        $out = ZabbixService::mediasWithSeverity($this->medias(), '59', 48);
        $this->assertSame([
            ['mediatypeid' => '1', 'sendto' => ['a@x.ua'], 'active' => '0', 'severity' => '48', 'period' => '1-7,00:00-24:00'],
            ['mediatypeid' => '16', 'sendto' => '123', 'active' => '1', 'severity' => '48', 'period' => '1-5,09:00-18:00'],
        ], $out, 'provisioned omitted (Zabbix keeps it), no read-only fields sent');
    }

    public function testProvisionedOrUnknownIsRefused(): void
    {
        $this->assertNull(ZabbixService::mediasWithSeverity($this->medias(), '60', 1));
        $this->assertNull(ZabbixService::mediasWithSeverity($this->medias(), '999', 1));
    }
}
