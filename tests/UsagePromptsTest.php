<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\UsagePrompts;

final class UsagePromptsTest extends TestCase
{
    private string $dir;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-usage-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function prompts(): UsagePrompts
    {
        return new UsagePrompts(new FileCache($this->dir, fn() => $this->now));
    }

    public function testTakeReturnsOnceAndPerChat(): void
    {
        $p = $this->prompts();
        $p->remember('42', 10, 11);
        $this->assertNull($p->take('43', 10), 'other chat');
        $this->assertSame(11, $p->take('42', 10));
        $this->assertNull($p->take('42', 10), 'taken once: further edits are ignored');
        $this->assertNull($p->take('42', 99), 'edit of a message without usage reply');
    }

    public function testExpiresAfterTtl(): void
    {
        $p = $this->prompts();
        $p->remember('42', 10, 11);
        $this->now += UsagePrompts::TTL;
        $this->assertNull($p->take('42', 10), 'Telegram no longer lets the bot delete it');
    }

    public function testKeepsOnlyRecentEntries(): void
    {
        $p = $this->prompts();
        for ($i = 1; $i <= 25; $i++) {
            $p->remember('42', $i, 100 + $i);
        }
        $this->assertNull($p->take('42', 1), 'oldest dropped');
        $this->assertSame(125, $p->take('42', 25));
    }

    public function testInvalidIdsIgnored(): void
    {
        $p = $this->prompts();
        $p->remember('42', 0, 5);
        $p->remember('42', 5, 0);
        $this->assertNull($p->take('42', 0));
        $this->assertNull($p->take('42', 5));
    }
}
