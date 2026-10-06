<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\MessageService;

final class MessageServiceChunkingTest extends TestCase
{
    public function testChunkRespectsLimitAndKeepsText(): void
    {
        $text = implode("\n", array_map(fn($i) => "рядок $i ".str_repeat('я', 90), range(1, 200)));
        $parts = MessageService::chunk($text);
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(4096, mb_strlen($p));
        }
        $this->assertSame($text, implode("\n", $parts));
    }

    public function testChunkSplitsVeryLongLine(): void
    {
        $parts = MessageService::chunk(str_repeat('x', 9000));
        $this->assertCount(3, $parts);
        $this->assertSame(9000, mb_strlen(implode('', $parts)));
    }

    public function testHtmlPreIsBalancedAcrossChunks(): void
    {
        $text = "<b>head</b>\n<pre>\n".implode("\n", array_fill(0, 600, 'line of output'))."\n</pre>";
        $parts = MessageService::chunk($text, true);
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(4096, mb_strlen($p));
            $this->assertSame(substr_count($p, '<pre'), substr_count($p, '</pre>'), 'pre має бути збалансований у кожній частині');
        }
    }

    public function testPackBlocks(): void
    {
        $blocks = array_fill(0, 30, str_repeat('b', 300));
        $packed = MessageService::packBlocks($blocks, "\n");
        $this->assertCount(3, $packed); // по 13 блоків (13*300+12 < 4096)
        foreach ($packed as $p) {
            $this->assertLessThanOrEqual(4096, mb_strlen($p));
        }
        $this->assertSame([], MessageService::packBlocks([]));
    }
}
