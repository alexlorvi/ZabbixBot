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
            'media' => 'Notification methods',
            'media_disabled' => 'disabled',
            'media_provisioned' => 'from LDAP',
            'media_missing' => 'not configured',
            'back' => 'Back',
            'close' => 'Close',
            'languageNames' => ['en' => 'English', 'ua' => 'Українська'],
        ];
    }

    private function media(): array
    {
        return [
            ['id' => '19', 'label' => 'Telegram', 'mask' => 48, 'active' => true, 'provisioned' => false],
            ['id' => '18', 'label' => 'Email (HTML)', 'mask' => 0, 'active' => false, 'provisioned' => false],
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
        [, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'en', 'reply', $this->media());
        $menuRow = $keyboard->toArray()['inline_keyboard'][1];
        $this->assertStringNotContainsString("\u{2705}", $menuRow[0]['text']);
        $this->assertStringContainsString("\u{2705}", $menuRow[1]['text']);
        $this->assertSame('set:menu:reply', $menuRow[1]['callback_data']);
    }

    public function testMainListsServerMediaWithCurrentLevels(): void
    {
        [$text, $keyboard] = SettingsCommand::renderFromState($this->i18n(), 'en', 'inline', $this->media());
        $this->assertStringContainsString("Telegram: High, Disaster", $text);
        $this->assertStringContainsString("Email (HTML) (disabled): -", $text);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $this->assertSame('set:media:19', $rows[2][0]['callback_data']);
        $this->assertSame('set:media:18', $rows[2][1]['callback_data']);
        $this->assertStringContainsString('Email (HTML)', $rows[2][1]['text']);
        $lastRow = $rows[array_key_last($rows)];
        $this->assertCount(1, $lastRow);
        $this->assertSame('set:close', $lastRow[0]['callback_data']);
        foreach ($rows as $row) {
            foreach ($row as $btn) {
                $this->assertNotSame('set:main', $btn['callback_data'], 'no Back on the main panel');
            }
        }
    }

    public function testMediaSubmenuSeverityCheckboxesAndBack(): void
    {
        // Average(8) + Disaster(32) = 40 => bits 3 and 5 checked
        [, $keyboard] = SettingsCommand::renderMediaFromState($this->i18n(), ['id' => '58', 'label' => 'Email', 'mask' => 40, 'active' => true, 'provisioned' => false]);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $this->assertStringContainsString("\u{2B1C}", $rows[0][0]['text']);
        $this->assertStringContainsString("\u{2705}", $rows[1][1]['text']);
        $this->assertStringContainsString("\u{2705}", $rows[2][1]['text']);
        $this->assertSame('set:sev:58:3', $rows[1][1]['callback_data']);
        $this->assertSame('set:main', $rows[3][0]['callback_data']);
    }

    public function testProvisionedMediaIsReadOnly(): void
    {
        [$text, $keyboard] = SettingsCommand::renderMediaFromState($this->i18n(), ['id' => '7', 'label' => 'Email', 'mask' => 32, 'active' => true, 'provisioned' => true]);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $this->assertCount(1, $rows, 'only Back');
        $this->assertStringContainsString('Disaster', $text);
        $this->assertStringContainsString('from LDAP', $text);
    }

    public function testMediaSubmenuWithoutMediaShowsOnlyBack(): void
    {
        [$text, $keyboard] = SettingsCommand::renderMediaFromState($this->i18n(), null);
        $rows = $keyboard->toArray()['inline_keyboard'];
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('not configured', $text);
    }

    public function testMediaEntriesFromServerData(): void
    {
        $medias = [
            ['mediaid' => '19', 'mediatypeid' => '16', 'sendto' => '123', 'active' => '0', 'severity' => '32', 'provisioned' => '0'],
            ['mediaid' => '18', 'mediatypeid' => '1', 'sendto' => ['a@x.ua'], 'active' => '1', 'severity' => '48', 'provisioned' => '0'],
            ['mediaid' => '20', 'mediatypeid' => '1', 'sendto' => ['b@x.ua', 'c@x.ua'], 'active' => '0', 'severity' => '0', 'provisioned' => '1'],
            ['mediaid' => '21', 'mediatypeid' => '99', 'sendto' => 'x', 'active' => '0', 'severity' => '1'],
        ];
        $e = SettingsCommand::mediaEntries($medias, ['16' => 'Telegram', '1' => 'Email (HTML)']);
        $this->assertSame(['id' => '19', 'label' => 'Telegram', 'mask' => 32, 'active' => true, 'provisioned' => false], $e[0]);
        $this->assertSame('Email (HTML) (a@x.ua)', $e[1]['label'], 'two media of one type - with address');
        $this->assertFalse($e[1]['active']);
        $this->assertSame('Email (HTML) (b@x.ua, c@x.ua)', $e[2]['label']);
        $this->assertTrue($e[2]['provisioned']);
        $this->assertSame('#99', $e[3]['label'], 'unknown type id');
    }
}
