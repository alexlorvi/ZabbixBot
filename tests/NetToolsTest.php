<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\NetTools;

final class NetToolsTest extends TestCase
{
    public function testInjectionAttemptsAreRejectedBeforeExec(): void
    {
        $n = new NetTools([], ['bad_ip' => 'Щось оце %s не схоже на IP', 'bad_ipv4' => 'Щось оце %s не схоже на IPv4']);
        foreach (['1.1.1.1; id', '$(id)', '1.1.1.1 && ls', "1.1.1.1\nid", ''] as $bad) {
            $this->assertStringContainsString('не схоже на IP', $n->cisco($bad));
            $this->assertStringContainsString('не схоже на IP', $n->apc($bad));
        }
        $this->assertStringContainsString('не схоже на IPv4', $n->cisco('::1'));
    }

    public function testDefaultTextsAreEnglish(): void
    {
        $this->assertSame('x does not look like an IPv4', (new NetTools([]))->cisco('x'));
    }

    public function testApcEscapesHtml(): void
    {
        $this->assertStringContainsString('&lt;b&gt;', (new NetTools([]))->apc('<b>'));
    }
}
