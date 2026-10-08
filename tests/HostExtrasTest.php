<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\HostExtras;

final class HostExtrasTest extends TestCase
{
    private const UNITS = ['d' => 'd', 'h' => 'h', 'm' => 'm'];

    public function testKeyMatchesTreatsBracketsLiterally(): void
    {
        $this->assertTrue(HostExtras::keyMatches('icmpping*', 'icmpping[172.24.16.46]'));
        $this->assertTrue(HostExtras::keyMatches('icmpping*', 'icmpping'));
        $this->assertTrue(HostExtras::keyMatches('zabbix[host,*,available]', 'zabbix[host,snmp,available]'));
        $this->assertFalse(HostExtras::keyMatches('zabbix[host,*,available]', 'zabbix[host,agent,status]'));
        $this->assertFalse(HostExtras::keyMatches('system.hw.model', 'system.hw.modelX'));
    }

    public function testLatest(): void
    {
        $now = 1_000_000;
        $items = [
            ['name' => 'Model', 'key_' => 'system.hw.model', 'lastvalue' => 'C1111-8P', 'lastclock' => $now - 7200, 'units' => '', 'value_type' => '1'],
            ['name' => 'Uptime', 'key_' => 'system.uptime[sysUpTime.0]', 'lastvalue' => '90061', 'lastclock' => $now - 60, 'units' => 'uptime', 'value_type' => '3'],
            ['name' => 'RTT', 'key_' => 'icmppingsec', 'lastvalue' => '0.0104', 'lastclock' => $now - 60, 'units' => 's', 'value_type' => '0'],
            ['name' => 'SNMP <avail>', 'key_' => 'zabbix[host,snmp,available]', 'lastvalue' => '1', 'lastclock' => $now - 60, 'units' => '', 'value_type' => '3',
                'valuemap' => ['mappings' => [['type' => '0', 'value' => '1', 'newvalue' => 'available']]]],
            ['name' => 'Never', 'key_' => 'icmppingloss', 'lastvalue' => '0', 'lastclock' => '0', 'units' => '%', 'value_type' => '0'],
            ['name' => 'Ignored', 'key_' => 'net.if.in[1]', 'lastvalue' => '5', 'lastclock' => $now, 'units' => 'bps', 'value_type' => '3'],
        ];
        $text = HostExtras::latest('R<1>', $items, ['icmpping*', 'zabbix[host,*,available]', 'system.uptime*', 'system.hw.model'],
            ['title' => 'Latest %s', 'none' => 'none', 'ago' => '%s ago', 'units' => self::UNITS], $now);
        $this->assertSame(implode("\n", [
            'Latest <b>R&lt;1&gt;</b>',
            '• Never: <b>—</b>',          // icmpping* - за назвою в межах шаблону
            '• RTT: <b>10.4 ms</b>',
            '• SNMP &lt;avail&gt;: <b>available (1)</b>',
            '• Uptime: <b>1d 1h</b>',
            '• Model: <b>C1111-8P</b> <i>(2h 0m ago)</i>',
        ]), $text);

        $this->assertSame("Latest <b>x</b>\nnone", HostExtras::latest('x', $items, ['nothing'], ['title' => 'Latest %s', 'none' => 'none', 'ago' => '', 'units' => self::UNITS], $now));
    }

    public function testEvents(): void
    {
        $now = 1_000_000;
        $events = [
            ['eventid' => '2', 'clock' => (string)($now - 600), 'name' => 'Link <down>', 'severity' => '3', 'r_clock' => 0],
            ['eventid' => '1', 'clock' => (string)($now - 7200), 'name' => 'Loss', 'severity' => '2', 'r_clock' => $now - 3600],
        ];
        $i18n = ['title' => 'Events: %s', 'none' => 'none', 'open' => 'open %s', 'closed' => 'ok %s',
            'severity' => [2 => 'W Warning', 3 => 'A Average'], 'units' => self::UNITS];
        $lines = explode("\n", HostExtras::events('H', $events, $i18n, $now));
        $this->assertSame('Events: <b>H</b>', $lines[0]);
        $this->assertStringStartsWith('A ', $lines[1]);
        $this->assertStringEndsWith(' Link &lt;down&gt; · open 10m /ev2', $lines[1]);
        $this->assertStringEndsWith(' Loss · ok 1h 0m /ev1', $lines[2]);
        $this->assertSame("Events: <b>H</b>\nnone", HostExtras::events('H', [], $i18n, $now));
    }

    public function testCommonPrefix(): void
    {
        $this->assertSame('Interface Gi0/0/1(=UT=): ', HostExtras::commonPrefix(['Interface Gi0/0/1(=UT=): Bits sent', 'Interface Gi0/0/1(=UT=): Bits received']));
        $this->assertSame('', HostExtras::commonPrefix(['Bits sent', 'Bits received'])); // не посеред слова
        $this->assertSame('', HostExtras::commonPrefix(['Only one']));
    }

    public function testCaption(): void
    {
        $series = [
            ['name' => 'If 1: Bits received', 'units' => 'bps', 'points' => [[1, 1000.0], [2, 3000.0]]],
            ['name' => 'If 1: Errors', 'units' => '', 'points' => []],
        ];
        $i18n = ['period' => 'last %s', 'last' => 'last (min · avg · max)', 'noData' => 'no data'];
        $this->assertSame(implode("\n", [
            "\u{1F4C8} <b>Traffic &amp; co</b>",
            'Host · last 24 h',
            'If 1',
            'last (min · avg · max)',
            "\u{1F7E9} Bits received: <b>3 Kbps</b> (1 Kbps · 2 Kbps · 3 Kbps)",
            "\u{1F7E6} Errors: no data",
        ]), HostExtras::caption('Traffic & co', 'Host', '24 h', $series, $i18n));

        $many = array_fill(0, 40, ['name' => str_repeat('x', 40), 'units' => '', 'points' => [[1, 1.0]]]);
        $caption = HostExtras::caption('G', 'H', 'p', $many, $i18n);
        $this->assertLessThanOrEqual(1024, mb_strlen(strip_tags($caption)));
        $this->assertStringEndsWith("\n…", $caption);
    }
}
