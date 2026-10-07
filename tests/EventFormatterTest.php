<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\EventFormatter;

final class EventFormatterTest extends TestCase
{
    private function i18n(): array
    {
        return [
            'severity' => [4 => "\u{1F534} High", 5 => "\u{1F7E3} Disaster"],
            'line' => '%s|%s (%s)|%s (%s)|/ev%s|%s %s',
            'summaryLine' => '%s %s /ev%s %s (%s) %s',
            'tagsLine' => "\nTAGS %s",
            'ackLine' => "\nACK %s - %s (%s)",
            'units' => ['d' => 'd', 'h' => 'h', 'm' => 'm'],
        ];
    }

    public function testNormalizeMergesProblemAndInfo(): void
    {
        $e = EventFormatter::normalize(
            ['eventid' => '7', 'name' => 'Down', 'clock' => '100', 'severity' => '5', 'acknowledged' => '1'],
            ['hosts' => [['host' => 'h1', 'name' => 'Host 1']], 'tags' => [['tag' => 'a', 'value' => 'b']]]
        );
        $this->assertSame('7', $e['eventid']);
        $this->assertSame(5, $e['severity']);
        $this->assertSame('Host 1', $e['hostName']);
        $this->assertTrue($e['acknowledged']);
        $this->assertSame([], $e['acknowledges']);
    }

    public function testNormalizeSurvivesMissingInfo(): void
    {
        $e = EventFormatter::normalize(['eventid' => '7', 'name' => 'x', 'clock' => 1], []);
        $this->assertSame('', $e['hostName']);
        $this->assertSame([], $e['tags']);
        $this->assertSame([], $e['acknowledges']);
        $this->assertFalse($e['acknowledged']);
    }

    public function testDuration(): void
    {
        $u = ['d' => 'd', 'h' => 'h', 'm' => 'm'];
        $this->assertSame('15m', EventFormatter::duration(900, $u));
        $this->assertSame('1h 28m', EventFormatter::duration(5280, $u));
        $this->assertSame('2d 3h', EventFormatter::duration(2 * 86400 + 3 * 3600 + 59, $u));
        $this->assertSame('0m', EventFormatter::duration(-5, $u));
    }

    public function testSeverityLabel(): void
    {
        $names = $this->i18n()['severity'];
        $this->assertSame("\u{1F534} High", EventFormatter::severityLabel(4, $names));
        $this->assertSame("\u{1F534}", EventFormatter::severityLabel(4, $names, true));
        $this->assertSame('3', EventFormatter::severityLabel(3, $names));
    }

    public function testFormatIncludesHostTagsAndAckAuthor(): void
    {
        $event = EventFormatter::normalize(
            ['eventid' => '9', 'name' => 'Door', 'clock' => 1000, 'severity' => 5, 'acknowledged' => 1],
            [
                'hosts' => [['host' => 'h', 'name' => 'Host']],
                'tags' => [['tag' => 'site', 'value' => 'A1'], ['tag' => 'flag', 'value' => '']],
                'acknowledges' => [['clock' => 1100, 'message' => 'on it', 'author' => 'Ivan Petrenko', 'userid' => '3']],
            ]
        );
        $text = EventFormatter::format($event, $this->i18n(), 1000 + 600);
        $this->assertStringContainsString("\u{1F7E3} Disaster|", $text);
        $this->assertStringContainsString('(10m)|Host (h)|/ev9|Door', $text);
        $this->assertStringContainsString("\u{2705}", $text);
        $this->assertStringContainsString("\nTAGS site:A1, flag", $text);
        $this->assertStringContainsString('on it (Ivan Petrenko)', $text);
    }

    public function testFormatWithoutHostShowsDash(): void
    {
        $event = EventFormatter::normalize(['eventid' => '1', 'name' => 'x', 'clock' => 0, 'severity' => 4], []);
        $this->assertStringContainsString('|- (-)|', EventFormatter::format($event, $this->i18n(), 60));
    }

    public function testSummary(): void
    {
        $event = EventFormatter::normalize(['eventid' => '5', 'name' => 'N', 'clock' => 0, 'severity' => 4], []);
        $this->assertStringContainsString("\u{1F534} ", EventFormatter::summary($event, $this->i18n()));
        $this->assertStringContainsString('/ev5', EventFormatter::summary($event, $this->i18n()));
    }
}
