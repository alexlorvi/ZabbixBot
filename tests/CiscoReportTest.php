<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\CiscoReport;
use ZabbixBot\Services\NetTools;

final class CiscoReportTest extends TestCase
{
    /** Відповіді snmpget/snmpbulkwalk (-On -Oq -Oe -Ot) ISR 1100, як у прод-прикладі 10.16.46.1. */
    private static function device(): array
    {
        $if = '.1.3.6.1.2.1.2.2.1';
        $ifx = '.1.3.6.1.2.1.31.1.1.1';
        $ports = [
            1 => ['Gi0/0/0', 'GigabitEthernet0/0/0', 6, 1, 1, '=DG='],
            2 => ['Gi0/0/1', 'GigabitEthernet0/0/1', 6, 1, 1, '=UT='],
            3 => ['Gi0/1/0', 'GigabitEthernet0/1/0', 6, 1, 1, ''],
            4 => ['Gi0/1/1', 'GigabitEthernet0/1/1', 6, 1, 1, ''],
            6 => ['Gi0/1/3', 'GigabitEthernet0/1/3', 6, 1, 2, ''],
            7 => ['Gi0/1/4', 'GigabitEthernet0/1/4', 6, 2, 2, ''],
            11 => ['Nu0', 'Null0', 1, 1, 1, ''],
            12 => ['Vl1', 'Vlan1', 53, 1, 2, ''],
            13 => ['Lo0', 'Loopback0', 24, 1, 1, ''],
            14 => ['Tu4', 'Tunnel4', 131, 1, 1, 'Каса <1>'],
            16 => ['Vl2', 'Vlan2', 53, 1, 1, ''],
            20 => ['Vl8', 'Vlan8', 53, 1, 1, ''],
        ];
        $ifTable = $ifxTable = [];
        foreach ($ports as $i => [$name, $descr, $type, $admin, $oper, $alias]) {
            $ifTable[] = "$if.2.$i $descr";
            $ifTable[] = "$if.3.$i $type";
            $ifTable[] = "$if.7.$i $admin";
            $ifTable[] = "$if.8.$i $oper";
            $ifTable[] = "$if.9.$i ".($i === 6 ? 100000000 - 360000 : 500);
            $ifxTable[] = "$ifx.1.$i $name";
            $ifxTable[] = "$ifx.15.$i ".($i === 4 ? 100 : 1000);
            // net-snmp віддає не-ASCII як Hex-STRING, порожні рядки - як ""
            $ifxTable[] = "$ifx.18.$i ".($alias === '' ? '""' : (preg_match('/^[ -~]*$/', $alias) ? $alias : strtoupper(implode(' ', str_split(bin2hex($alias), 2)))));
        }
        $ip = '.1.3.6.1.2.1.4.20.1';
        $addrs = ['10.16.46.1' => [16, '255.255.255.192'], '10.240.6.17' => [20, '255.255.255.240'],
            '10.252.16.46' => [1, '255.255.0.0'], '172.20.16.46' => [13, '255.255.255.255'], '192.168.1.1' => [99, '255.255.255.0']];
        $ipTable = [];
        foreach ($addrs as $a => [$idx, $mask]) {
            $ipTable[] = "$ip.2.$a $idx";
            $ipTable[] = "$ip.3.$a $mask";
        }
        $t = CiscoReport::TRUNK_TABLE;
        return [
            'snmpget' => [
                '.1.3.6.1.2.1.1.1.0 "Cisco IOS Software [Fuji], ISR Software (ARMV8EB_LINUX_IOSD-UNIVERSALK9_IAS-M), Version 16.9.5, RELEASE SOFTWARE (fc1)',
                'Technical Support: http://www.cisco.com/techsupport"',
                '.1.3.6.1.2.1.1.3.0 100000000',
                '.1.3.6.1.2.1.1.5.0 AZK-1646',
            ],
            CiscoReport::IF_TABLE => $ifTable,
            CiscoReport::IFX_TABLE => $ifxTable,
            CiscoReport::IP_TABLE => $ipTable,
            "$t.14" => ["$t.14.3 1", "$t.14.4 2"],
            "$t.5" => ["$t.5.3 1", "$t.5.4 1"],
            "$t.4" => ["$t.4.3 3C 80 00 00 00 00 00 00 00 00 00 00 00 00 00 00", '00 00 00 00'],
            CiscoReport::VM_VLAN => [CiscoReport::VM_VLAN.' No Such Object available on this agent at this OID'],
        ];
    }

    private static function tools(array $device, ?array &$cmds = []): NetTools
    {
        return new NetTools(['snmp_community_cisco' => 'pub'], [], function (string $cmd) use ($device, &$cmds): array {
            $cmds[] = $cmd;
            if (str_contains($cmd, 'snmpget ')) {
                return $device['snmpget'] ?? ['Timeout: No Response from 10.16.46.1.'];
            }
            preg_match("/'(\.[0-9.]+)' 2>&1$/", $cmd, $m);
            return $device[$m[1]] ?? [];
        });
    }

    public function testReport(): void
    {
        $out = self::tools(self::device(), $cmds)->cisco('10.16.46.77');

        $this->assertStringContainsString("<b>AZK-1646</b> <code>10.16.46.1</code>\nCisco IOS 16.9.5 (ISR) · uptime 11d 13h", $out);
        $this->assertStringContainsString("Ports: 4 up (\u{1F7E2}) · 1 down (\u{1F534}) · 1 disabled (\u{26AA})", $out);
        $this->assertStringContainsString("\u{1F7E2} <code>Gi0/0/0</code> <code>10.252.16.46/16</code> · <i>=DG=</i>", $out);
        $this->assertStringContainsString("\u{1F7E2} <code>Gi0/1/0</code> trunk 2-5,8\n", $out); // native 1 - не показується
        $this->assertStringContainsString("\u{1F7E2} <code>Gi0/1/1</code> 100M\n", $out);       // не-1G швидкість
        $this->assertStringContainsString("\u{1F534} <code>Gi0/1/3</code> down 1h 0m", $out);
        $this->assertStringContainsString("\u{26AA} <code>Gi0/1/4</code> disabled\n\n\u{1F534} <code>Vl1</code>", $out); // фізичні окремо від віртуальних
        $this->assertStringContainsString("\u{1F534} <code>Vl1</code> down since boot\n", $out);
        $this->assertStringContainsString("<code>Lo0</code> <code>172.20.16.46/32</code>", $out);
        $this->assertStringContainsString('<code>Tu4</code> <i>Каса &lt;1&gt;</i>', $out);
        $this->assertStringContainsString('<code>Vl8</code> <code>10.240.6.17/28</code>', $out);
        $this->assertStringNotContainsString('Nu0', $out);
        $this->assertStringEndsWith("<b>Other IP addresses</b>\n<code>192.168.1.1/24</code> #99", $out);

        $this->assertStringContainsString("snmpget -v2c -c 'pub'", $cmds[0]);
        $this->assertStringContainsString("'10.16.46.1'", $cmds[0]);
        $this->assertCount(8, $cmds);
    }

    public function testNoSnmpResponseStopsAfterFirstCall(): void
    {
        $device = self::device();
        unset($device['snmpget']);
        $out = self::tools($device, $cmds)->cisco('10.16.46.77');
        $this->assertSame('No SNMP response from 10.16.46.1', $out);
        $this->assertCount(1, $cmds);
    }

    public function testMissingCommunityDoesNotRunAnything(): void
    {
        $ran = false;
        $out = (new NetTools([], [], function () use (&$ran) { $ran = true; return []; }))->cisco('10.0.0.5');
        $this->assertStringContainsString('net.snmp_community_cisco', $out);
        $this->assertFalse($ran);
    }

    public function testHelpers(): void
    {
        $this->assertSame([2, 3, 4, 5, 8], CiscoReport::vlansFromBitmap('3C 80'));
        $this->assertSame('2-5,8', CiscoReport::ranges([8, 2, 3, 4, 5]));
        $this->assertSame('all', CiscoReport::ranges(range(0, 1023)));
        $this->assertSame(26, CiscoReport::prefix('255.255.255.192'));
        $this->assertSame('10G', CiscoReport::speed(10000));
        $this->assertSame('Опис', CiscoReport::value('D0 9E D0 BF D0 B8 D1 81'));
        $this->assertSame('a "b"', CiscoReport::value('"a \"b\""'));
        $this->assertSame('53', CiscoReport::value('53')); // одне число - не Hex-STRING
    }
}
