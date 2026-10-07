<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\CommandList;

final class CommandListTest extends TestCase
{
    private array $commands = ['ping' => 'Ping', 'reset' => 'Reset cache', 'menu' => 'Menu'];

    public function testNonAdminDoesNotSeeAdminCommands(): void
    {
        $text = CommandList::render($this->commands, ['reset'], false);
        $this->assertStringContainsString('/ping - Ping', $text);
        $this->assertStringNotContainsString('/reset', $text);
    }

    public function testAdminSeesEverything(): void
    {
        $text = CommandList::render($this->commands, ['reset'], true);
        $this->assertStringContainsString('/reset - Reset cache', $text);
        $this->assertStringContainsString('/menu - Menu', $text);
    }
}
