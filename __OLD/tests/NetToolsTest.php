<?php
declare(strict_types=1);

namespace ZbxBot\Tests;

use PHPUnit\Framework\TestCase;
use ZbxBot\Net\NetTools;

final class NetToolsTest extends TestCase
{
    public function testInjectionAttemptsAreRejectedBeforeExec(): void
    {
        $n = new NetTools([], '/nonexistent');
        foreach (['1.1.1.1; id', '$(id)', '1.1.1.1 && ls', "1.1.1.1\nid", ''] as $bad) {
            $this->assertStringContainsString('не схоже на IP', $n->ping($bad));
            $this->assertStringContainsString('не схоже на IP', $n->cisco($bad));
            $this->assertStringContainsString('не схоже на IP', $n->apc($bad));
        }
        $this->assertStringContainsString('не схоже на IPv4', $n->cisco('::1'));
    }

    public function testApcEscapesHtml(): void
    {
        $this->assertStringContainsString('&lt;b&gt;', (new NetTools([], '/x'))->apc('<b>'));
    }
}
