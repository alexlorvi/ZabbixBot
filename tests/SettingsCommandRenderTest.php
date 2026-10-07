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
            'media_names' => ['tg' => 'Telegram', 'email' => 'Email'],
            'media_missing' => 'not configured',
            'back' => 'Back',
            'close' => 'Close',
            'languageNames' => ['en' => 'English', 'ua' => 'Українська'],
        ];
    }

    public function testTextReflectsCurrentState(): void
    {
        [$text] = SettingsCommand::renderFromState($this->i18n(), 'ua', 'reply', []);
        $this->assertStringContainsString('Language: Українська', $text);
        $this->assertStringContainsString('Menu style: Reply keyboard', $text);
    }

    public function testLanguageRowMarksCurrentLanguage(): void
    {
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'ua', 'inline', []);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $langRow = $rows[0];
        $this->assertStringContainsString("\u{2705}", $langRow[1]['text']); // 'ua' is the 2nd language in the fixture
        $this->assertStringNotContainsString("\u{2705}", $langRow[0]['text']);
        $this->assertSame('set:lang:en', $langRow[0]['callback_data']);
        $this->assertSame('set:lang:ua', $langRow[1]['callback_data']);
    }

    public function testMenuStyleRowMarksCurrentStyle(): void
    {
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'en', 'reply', ['tg']);
        $menuRow = $keyboard->toArray()['inline_keyboard'][1];
        $this->assertStringNotContainsString("\u{2705}", $menuRow[0]['text']);
        $this->assertStringContainsString("\u{2705}", $menuRow[1]['text']);
        $this->assertSame('set:menu:reply', $menuRow[1]['callback_data']);
    }

    public function testMainHasMediaButtonsAndCloseButNoBack(): void
    {
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'en', 'inline', ['tg', 'email']);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $this->assertSame('set:media:tg', $rows[2][0]['callback_data']);
        $this->assertSame('set:media:email', $rows[2][1]['callback_data']);
        $lastRow = $rows[array_key_last($rows)];
        $this->assertCount(1, $lastRow);
        $this->assertSame('set:close', $lastRow[0]['callback_data']);
        foreach ($rows as $row) {
            foreach ($row as $btn) {
                $this->assertNotSame('set:back', $btn['callback_data']);
            }
        }
    }

    public function testMediaSubmenuSeverityCheckboxesAndBack(): void
    {
        // Average(8) + Disaster(32) = 40 => bits 3 and 5 checked
        [, $keyboard] = SettingsCommand::renderMediaFromState($this->i18n(), 'email', 40);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $this->assertStringContainsString("\u{2B1C}", $rows[0][0]['text']);
        $this->assertStringContainsString("\u{2705}", $rows[1][1]['text']);
        $this->assertStringContainsString("\u{2705}", $rows[2][1]['text']);
        $this->assertSame('set:sev:email:3', $rows[1][1]['callback_data']);
        $this->assertSame('set:main', $rows[3][0]['callback_data']);
    }

    public function testMediaSubmenuWithoutMediaShowsOnlyBack(): void
    {
        [$text, $keyboard] = SettingsCommand::renderMediaFromState($this->i18n(), 'tg', null);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('not configured', $text);
    }
}
