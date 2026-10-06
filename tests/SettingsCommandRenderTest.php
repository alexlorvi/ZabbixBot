<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Commands\SettingsCommand;

final class SettingsCommandRenderTest extends TestCase
{
    private function i18n(): array
    {
        return [
            'title' => 'Settings',
            'lang' => 'Language',
            'severity' => 'Notification severity',
            'menu_style' => 'Menu style',
            'menu_style_inline' => 'Inline',
            'menu_style_reply' => 'Reply keyboard',
            'severity_levels' => ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'],
            'back' => 'Back',
            'close' => 'Close',
            'languageNames' => ['en' => 'English', 'ua' => 'Українська'],
        ];
    }

    public function testTextReflectsCurrentState(): void
    {
        [$text] = SettingsCommand::renderFromState($this->i18n(), 'ua', 'reply', 0);
        $this->assertStringContainsString('Language: Українська', $text);
        $this->assertStringContainsString('Menu style: Reply keyboard', $text);
    }

    public function testLanguageRowMarksCurrentLanguage(): void
    {
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'ua', 'inline', 0);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $langRow = $rows[0];
        $this->assertStringContainsString("\u{2705}", $langRow[1]['text']); // 'ua' is the 2nd language in the fixture
        $this->assertStringNotContainsString("\u{2705}", $langRow[0]['text']);
        $this->assertSame('set:lang:en', $langRow[0]['callback_data']);
        $this->assertSame('set:lang:ua', $langRow[1]['callback_data']);
    }

    public function testSeverityCheckboxesReflectBitmask(): void
    {
        // Average(8) + Disaster(32) = 40 => bits 3 and 5 checked
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'en', 'inline', 40);
        $rows = $keyboard->toArray()['inline_keyboard'];
        // rows[0] = languages, rows[1..3] = severity pairs (bits 0-1, 2-3, 4-5), rows[4] = menu style, rows[5] = back/close
        $this->assertStringContainsString("\u{2B1C}", $rows[1][0]['text']); // bit 0 Not classified - off
        $this->assertStringContainsString("\u{2B1C}", $rows[1][1]['text']); // bit 1 Information - off
        $this->assertStringContainsString("\u{2B1C}", $rows[2][0]['text']); // bit 2 Warning - off
        $this->assertStringContainsString("\u{2705}", $rows[2][1]['text']); // bit 3 Average - ON
        $this->assertStringContainsString("\u{2B1C}", $rows[3][0]['text']); // bit 4 High - off
        $this->assertStringContainsString("\u{2705}", $rows[3][1]['text']); // bit 5 Disaster - ON
        $this->assertSame('set:sev:3', $rows[2][1]['callback_data']);
        $this->assertSame('set:sev:5', $rows[3][1]['callback_data']);
    }

    public function testMenuStyleRowMarksCurrentStyle(): void
    {
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'en', 'reply', 0);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $menuRow = $rows[4];
        $this->assertStringNotContainsString("\u{2705}", $menuRow[0]['text']); // inline - not selected
        $this->assertStringContainsString("\u{2705}", $menuRow[1]['text']);   // reply - selected
        $this->assertSame('set:menu:inline', $menuRow[0]['callback_data']);
        $this->assertSame('set:menu:reply', $menuRow[1]['callback_data']);
    }

    public function testBackAndCloseButtonsPresent(): void
    {
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'en', 'inline', 0);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $lastRow = $rows[array_key_last($rows)];
        $this->assertSame('set:back', $lastRow[0]['callback_data']);
        $this->assertSame('set:close', $lastRow[1]['callback_data']);
    }
}
