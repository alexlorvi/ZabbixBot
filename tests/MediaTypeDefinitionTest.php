<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Commands\CLI\MediaTypeCommand;
use ZabbixBot\Services\MediaTypeDefinition;

final class MediaTypeDefinitionTest extends TestCase
{
    private function def(string $lang = 'ua', string $url = 'https://<bot-host>/alert.php', string $token = '<alerts.token>'): MediaTypeDefinition
    {
        return new MediaTypeDefinition($url, $token, $lang, MediaTypeDefinition::DEFAULT_NAME, '', __DIR__.'/../docs/zabbix-mediatype.js');
    }

    public function testDocsExportIsInSync(): void
    {
        $this->assertSame(
            $this->def()->exportYaml(),
            file_get_contents(__DIR__.'/../docs/zbx_export_mediatypes.yaml'),
            'regenerate: php console.php app:mediatype export --placeholders --lang=ua -o docs/zbx_export_mediatypes.yaml'
        );
    }

    public function testParameters(): void
    {
        $params = array_column($this->def('en', 'https://x/alert.php', 'secret')->parameters(), 'value', 'name');
        $this->assertSame('https://x/alert.php', $params['url']);
        $this->assertSame('secret', $params['token']);
        $this->assertSame('{ALERT.SENDTO}', $params['sendto']);
        $this->assertSame('en', $params['lang'], 'button language = --lang');
        $this->assertSame('{EVENT.ID}', $params['event_id']);
        $names = array_keys($params);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names, 'sorted like a Zabbix export');
    }

    public function testApiFields(): void
    {
        $f = $this->def('ua')->apiFields();
        $this->assertSame(4, $f['type']);
        $this->assertSame(MediaTypeDefinition::DEFAULT_NAME, $f['name']);
        $this->assertCount(5, $f['message_templates']);
        $this->assertStringContainsString('X-Alert-Token', $f['script']);
        $this->assertStringContainsString('Виявлено проблему', $f['message_templates'][0]['subject']);
        $this->assertStringContainsString('Problem detected', $this->def('en')->apiFields()['message_templates'][0]['subject']);
    }

    public function testUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->def('de');
    }

    public function testYamlScalars(): void
    {
        $this->assertSame("a: 'it''s'\n", MediaTypeDefinition::yaml(['a' => "it's"]));
        $this->assertSame("a: |\n  x\n\n  y\n", MediaTypeDefinition::yaml(['a' => "x\n\ny\n"]));
        $this->assertSame("a: |-\n  x\n  y\n", MediaTypeDefinition::yaml(['a' => "x\ny"]));
        $this->assertSame("a: |2\n   x\n  y\n", MediaTypeDefinition::yaml(['a' => " x\ny\n"]));
        $this->assertSame("l:\n  - k: 'v'\n    m: 'w'\n", MediaTypeDefinition::yaml(['l' => [['k' => 'v', 'm' => 'w']]]));
    }

    public function testDeriveUrl(): void
    {
        $this->assertSame('https://h/bot/alert.php', MediaTypeCommand::deriveUrl('https://h/bot/index.php'));
        $this->assertSame('https://h/bot/alert.php', MediaTypeCommand::deriveUrl('https://h/bot/'));
        $this->assertSame('https://h/alert.php', MediaTypeCommand::deriveUrl('https://h'));
        $this->assertSame('', MediaTypeCommand::deriveUrl(''));
    }
}
