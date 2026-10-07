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

    public function testSecretValid(): void
    {
        $this->assertTrue(Router::secretValid('', ''));
        $this->assertTrue(Router::secretValid('', 'anything'));
        $this->assertTrue(Router::secretValid('s3cret', 's3cret'));
        $this->assertFalse(Router::secretValid('s3cret', ''));
        $this->assertFalse(Router::secretValid('s3cret', 'wrong'));
    }
}
