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
        $this->assertSame(['host', 'c:59534:24h'], Router::classify('host:c:59534:24h'));
        $this->assertSame(['up', '10.0.0.1'], Router::classify('up:10.0.0.1'));
        $this->assertSame(['up', 'x:0123456789'], Router::classifyCallback('up:x:0123456789'));
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

    public function testAck(): void
    {
        $this->assertSame(['ack', '900'], Router::classifyCallback('ack:900'));
        $this->assertSame(['ackmsg', '900'], Router::classifyCallback('ackmsg:900'));
        $this->assertSame('ignored', Router::classifyCallback('ack:9x')[0]);
        $this->assertSame('ignored', Router::classifyCallback('ackmsg:')[0]);
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

    public function testParseNet(): void
    {
        $this->assertSame(['tool' => 'ping', 'target' => '10.0.0.1', 'count' => 200], Router::parseNet('ping.200:10.0.0.1'));
        $this->assertSame(['tool' => 'ping', 'target' => '10.0.0.1', 'count' => null], Router::parseNet('ping:10.0.0.1'), 'old host card buttons');
        $this->assertSame(['tool' => 'ping', 'target' => '2001:db8::1', 'count' => 4], Router::parseNet('ping.4:2001:db8::1'), 'IPv6 keeps its colons');
        $this->assertSame(['tool' => 'cisco', 'target' => '10.0.0.1', 'count' => null], Router::parseNet('cisco:10.0.0.1'));
        $this->assertSame(['net', 'cancel'], Router::classifyCallback('net:cancel'));
        $this->assertSame(['tool' => 'cancel', 'target' => '', 'count' => null], Router::parseNet('cancel'), 'Cancel carries no pid - the chat\'s own job is cancelled');
    }

    public function testRepeatMarkup(): void
    {
        $kb = json_decode(Router::repeatMarkup('ping', '10.0.0.1', 200, 'Repeat'), true);
        $this->assertSame('net:ping.200:10.0.0.1', $kb['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame(['net', 'ping.200:10.0.0.1'], Router::classifyCallback($kb['inline_keyboard'][0][0]['callback_data']), 'round trip');
        $this->assertSame('net:apc:10.0.0.1', json_decode(Router::repeatMarkup('apc', '10.0.0.1', null, 'R'), true)['inline_keyboard'][0][0]['callback_data']);
        $this->assertNull(Router::repeatMarkup('ping', str_repeat('a', 60).'.example.com', 4, 'R'), 'over 64 bytes - no button');
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
