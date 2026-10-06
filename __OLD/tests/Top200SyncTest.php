<?php
declare(strict_types=1);

namespace ZbxBot\Tests;

use PHPUnit\Framework\TestCase;
use ZbxBot\Top200Sync;

final class Top200SyncTest extends TestCase
{
    public function testPlan(): void
    {
        $routers = [
            ['hostid' => '1', 'inventory' => ['tag' => '5']],
            ['hostid' => '2', 'inventory' => ['tag' => '200']],
            ['hostid' => '3', 'inventory' => ['tag' => '201']],
            ['hostid' => '4', 'inventory' => []],               // порожній inventory (Zabbix віддає [])
            ['hostid' => '13747', 'inventory' => ['tag' => '999']],
        ];
        // 3 - застарілий, 99 - доданий вручну (не роутер), 1 - уже є
        $plan = Top200Sync::plan($routers, ['1', '3', '99']);
        $this->assertSame(['2', '13747'], $plan['add']);
        $this->assertSame(['3'], $plan['remove']);
        $this->assertSame(3, $plan['wanted']);
    }

    public function testNoChangesWhenInSync(): void
    {
        $plan = Top200Sync::plan([['hostid' => '1', 'inventory' => ['tag' => '1']]], ['1']);
        $this->assertSame([], $plan['add']);
        $this->assertSame([], $plan['remove']);
    }
}
