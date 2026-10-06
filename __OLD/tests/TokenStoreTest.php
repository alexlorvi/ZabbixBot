<?php
declare(strict_types=1);

namespace ZbxBot\Tests;

use PHPUnit\Framework\TestCase;
use ZbxBot\Storage\TokenStore;

final class TokenStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-tok-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir), ['.', '..']) as $f) {
            unlink($this->dir.'/'.$f);
        }
        rmdir($this->dir);
    }

    public function testEncryptedRoundTripAndPerms(): void
    {
        $key = base64_encode(random_bytes(32));
        $s = new TokenStore($this->dir, $key);
        $tok = str_repeat('a', 64);
        $this->assertTrue($s->put('123', $tok, 5000));
        $raw = file_get_contents($this->dir.'/123.key');
        $this->assertStringStartsWith('enc1:', $raw);
        $this->assertStringNotContainsString($tok, $raw);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir.'/123.key')), -4));
        $this->assertSame(['token' => $tok, 'exp' => 5000], $s->get('123'));
        $this->assertNull((new TokenStore($this->dir, base64_encode(random_bytes(32))))->get('123'), 'чужий ключ');
    }

    public function testLegacyPlainTokenIsReadAndMigrated(): void
    {
        $tok = str_repeat('b', 64);
        file_put_contents($this->dir.'/7.key', $tok);
        $s = new TokenStore($this->dir, base64_encode(random_bytes(32)));
        $this->assertSame(['token' => $tok, 'exp' => null], $s->get('7'));
        $this->assertStringStartsWith('enc1:', file_get_contents($this->dir.'/7.key'));
        $this->assertSame($tok, $s->get('7')['token']);
    }

    public function testWithoutKeyStoresJsonAndRejectsGarbage(): void
    {
        $s = new TokenStore($this->dir);
        $tok = str_repeat('c', 64);
        $s->put('9', $tok, null);
        $this->assertSame(['token' => $tok, 'exp' => null], $s->get('9'));
        file_put_contents($this->dir.'/5.key', 'short');
        $this->assertNull($s->get('5'));
        $this->assertNull($s->get('404'));
    }

    public function testChatIdCannotEscapeDirectory(): void
    {
        $s = new TokenStore($this->dir);
        $s->put('../../evil', str_repeat('d', 64), null);
        $this->assertFileExists($this->dir.'/.key'); // лишилися тільки цифри/мінус
        $this->assertFileDoesNotExist(dirname($this->dir).'/evil.key');
    }
}
