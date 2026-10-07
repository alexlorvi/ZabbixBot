<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Commands\GroupEventsCommand;

final class GroupEventsCommandTest extends TestCase
{
    public function testReplyTypeFromAlias(): void
    {
        $this->assertSame('full', GroupEventsCommand::replyTypeFromText('/zabbixFull'));
        $this->assertSame('full', GroupEventsCommand::replyTypeFromText('/zabbixfull'));
        $this->assertSame('list', GroupEventsCommand::replyTypeFromText('/zabbixList'));
        $this->assertSame('list', GroupEventsCommand::replyTypeFromText('/zabbix'));
        // аргументи після команди не впливають - для них є {replyType}
        $this->assertSame('list', GroupEventsCommand::replyTypeFromText('/events Fuller'));
    }
}
