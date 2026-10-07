<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\Router;

final class RouterTest extends TestCase
{
    public function testClassify(): void
    {
        $this->assertSame(['ev', '211751771'], Router::classify('/ev211751771'));
        $this->assertSame(['hostid', '10'], Router::classify('/hostid10'));
        $this->assertSame(['menu', 'full'], Router::classify('menu:full'));
        $this->assertSame(['set', 'sev:tg:3'], Router::classify('set:sev:tg:3'));
        $this->assertSame(['sec', '3600'], Router::classify('/3600sec'));
        $this->assertSame(['hours', '24'], Router::classify('/24h'));
        $this->assertSame(['menuaction', 'Full'], Router::classify('Full', ['Full' => []]));
        $this->assertSame(['text', 'hello'], Router::classify('hello'));
        $this->assertSame(['command', '/ping 1.1.1.1'], Router::classify('/ping 1.1.1.1'));
    }

    public function testNonMatchingPatternsFallThroughToCommand(): void
    {
        $this->assertSame('command', Router::classify('/evabc')[0]);
        $this->assertSame('command', Router::classify('/ev')[0]);
        $this->assertSame('command', Router::classify('/hostid')[0]);
        $this->assertSame('command', Router::classify('/events')[0]);
    }

    public function testCallbackPrefixesBeatGenericText(): void
    {
        // menu:/set: не мають бути проковтнуті catch-all "текстом"
        $this->assertSame('menu', Router::classify('menu:reset')[0]);
        $this->assertSame('set', Router::classify('set:close')[0]);
    }

    public function testCallback(): void
    {
        $this->assertSame(['net', 'ping:10.0.0.1'], Router::classifyCallback('net:ping:10.0.0.1'));
        // кнопки зі старих карток хоста
        $this->assertSame(['net', 'ping:10.0.0.1'], Router::classifyCallback('/ping 10.0.0.1'));
        $this->assertSame(['net', 'apc:10.0.0.1'], Router::classifyCallback('/apc 10.0.0.1'));
        $this->assertSame(['hostid', '10'], Router::classifyCallback('/hostid10'));
        $this->assertSame(['set', 'close'], Router::classifyCallback('set:close'));
        // SDK не вміє команди з callback_query - не передаємо
        $this->assertSame('ignored', Router::classifyCallback('/help')[0]);
        $this->assertSame('ignored', Router::classifyCallback('whatever')[0]);
    }

    public function testSecretValid(): void
    {
        $this->assertTrue(Router::secretValid('', ''));
        $this->assertTrue(Router::secretValid('', 'anything'));
        $this->assertTrue(Router::secretValid('s3cret', 's3cret'));
        $this->assertFalse(Router::secretValid('s3cret', ''));
        $this->assertFalse(Router::secretValid('s3cret', 'wrong'));
    }
}
