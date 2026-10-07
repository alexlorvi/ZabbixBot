<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\AlertFormatter;

require_once __DIR__.'/stubs.php';

final class AlertFormatterTest extends TestCase
{
    private function i18n(): array
    {
        $messages = require __DIR__.'/../config/messages.ua.php';
        return $messages['alert'] + ['severity' => $messages['user']['severity'], 'units' => $messages['main']['durUnits']];
    }

    private function problem(array $over = []): array
    {
        return $over + [
            'event_source' => '0', 'event_id' => '900', 'event_name' => 'Door open', 'event_severity' => '4',
            'event_date' => '2026.10.07', 'event_time' => '10:00:00', 'host_name' => 'AZK-01', 'host_ip' => '10.0.0.1',
            'inventory_tag' => '12', 'event_tags' => 'class:door', 'event_opdata' => '', 'trigger_description' => '',
            'trigger_url' => '', 'recovery_date' => '', 'recovery_time' => '', 'uptime' => '',
        ];
    }

    public function testProblem(): void
    {
        $text = AlertFormatter::render($this->problem(), 'problem', $this->i18n());
        $this->assertStringStartsWith("\u{1F534} <b>Виявлено проблему</b> 2026.10.07 о 10:00:00", $text);
        $this->assertStringContainsString('<b>Хост:</b> <i>AZK-01 (10.0.0.1)</i>', $text);
        $this->assertStringContainsString("<i>\u{1F534} Висока</i>", $text);
        $this->assertStringEndsWith('/ev900', $text);
        // порожні поля - рядки пропущені, зайвих порожніх рядків немає
        $this->assertStringNotContainsString('Операційні дані', $text);
        $this->assertStringNotContainsString('Опис проблеми', $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
    }

    public function testValuesAreHtmlEscaped(): void
    {
        $text = AlertFormatter::render($this->problem(['event_name' => 'CPU <b>x</b> & load', 'trigger_description' => 'a < b']), 'problem', $this->i18n());
        $this->assertStringContainsString('CPU &lt;b&gt;x&lt;/b&gt; &amp; load', $text);
        $this->assertStringContainsString("<b>Опис проблеми:</b>\na &lt; b", $text);
    }

    public function testRecoveryWithUptime(): void
    {
        $text = AlertFormatter::render($this->problem([
            'recovery_date' => '2026.10.07', 'recovery_time' => '11:00:00', 'event_duration' => '1h', 'uptime' => '93784',
        ]), 'recovery', $this->i18n());
        $this->assertStringStartsWith("\u{2705} <b>Проблему усунуто</b> 2026.10.07 о 11:00:00", $text);
        $this->assertStringContainsString('<b>Аптайм пристрою:</b> 1д 2г', $text);
        $this->assertStringContainsString('<b>Тривалість проблеми:</b> <i>1h</i>', $text);
    }

    public function testUpdateTranslatesZabbixWords(): void
    {
        $text = AlertFormatter::render($this->problem([
            'update_user' => 'Іван Петренко (ivan)', 'update_action' => 'acknowledged and commented',
            'update_message' => 'Виїхали', 'update_date' => '2026.10.07', 'update_time' => '10:05:00',
            'event_status' => 'PROBLEM', 'ack_status' => 'Yes',
        ]), 'update', $this->i18n());
        $this->assertStringContainsString('Іван Петренко (ivan) - квитував і прокоментував', $text);
        $this->assertStringContainsString('<b>Статус:</b> проблема, <b>квитовано:</b> так', $text);
        $this->assertStringContainsString('<i>Виїхали</i>', $text);
    }

    public function testEnglish(): void
    {
        $m = require __DIR__.'/../config/messages.php';
        $i18n = $m['alert'] + ['severity' => $m['user']['severity'], 'units' => $m['main']['durUnits']];
        $text = AlertFormatter::render($this->problem(), 'problem', $i18n);
        $this->assertStringContainsString('<b>Problem detected</b>', $text);
        $this->assertStringContainsString("<i>\u{1F534} High</i>", $text);
    }

    public function testFallsBackWithoutStructuredFields(): void
    {
        $this->assertNull(AlertFormatter::render(['subject' => 'x'], 'problem', $this->i18n()), 'старий медіатип');
        $this->assertNull(AlertFormatter::render($this->problem(['event_source' => '1']), 'problem', $this->i18n()), 'discovery');
        $this->assertNull(AlertFormatter::render($this->problem(['event_name' => '']), 'problem', $this->i18n()));
    }
}
