<?php
declare(strict_types=1);

namespace ZbxBot\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ZbxBot\Bot\Access;
use ZbxBot\Bot\Commands;
use ZbxBot\Bot\Router;
use ZbxBot\Net\NetTools;
use ZbxBot\Bot\UserTokens;
use ZbxBot\Zabbix\ZabbixService;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $commands = new Commands(
            $this->createMock(ZabbixService::class),
            $this->createMock(UserTokens::class),
            new NetTools([], '/nonexistent'),
        );
        $this->router = new Router();
        $commands->register($this->router);
    }

    /** @return array<string,array{string,?string,?string}> */
    public static function cases(): array
    {
        return [
            'start' => ['/start', 'start', null],
            'ping' => ['/ping 1.1.1.1', 'ping', null],
            'ping count' => ['/ping5 a b', 'ping', null],
            'ping alone' => ['/ping', 'ping', null],
            'pingfoo' => ['/pingfoo', null, null],
            'ping 3 digits' => ['/ping100 x', null, null],
            'sec' => ['/3600sec', 'seconds', '3600'],
            'xsec' => ['/xsec', null, null],
            'ev' => ['/ev123', 'event', '123'],
            'evil' => ['/evil', null, null],
            'top200' => ['/top200list', null, null],
            'cisco' => ['/cisco 10.0.0.5', 'cisco', '10.0.0.5'],
            'cisco no arg' => ['/cisco', null, null],
            'apc' => ['/apc 10.0.0.5', 'apc', '10.0.0.5'],
            'reset' => ['/reset', 'reset', null],
            'tkn removed' => ['/tkn', null, null],
        ];
    }

    #[DataProvider('cases')]
    public function testMatch(string $text, ?string $method, ?string $arg): void
    {
        $m = $this->router->match($text);
        if ($method === null && !in_array($text, ['/top200list'], true)) {
            $this->assertNull($m);
            return;
        }
        $this->assertNotNull($m);
        if ($method !== null) {
            $this->assertSame($method, $m[0][1]);
        }
        if ($arg !== null) {
            $this->assertSame($arg, $m[2][1]);
        }
    }

    public function testAccessLevels(): void
    {
        $this->assertSame(Access::Anyone, $this->router->match('/start')[1]);
        $this->assertSame(Access::User, $this->router->match('/menu')[1]);
        $this->assertSame(Access::Admin, $this->router->match('/reset')[1]);
    }

    public function testButtonsAndGroupCommandsMatch(): void
    {
        $this->assertNotNull($this->router->match("\u{1F4CB} Список активних"));
        $this->assertNotNull($this->router->match("\u{1F4D6} Деталізація активних"));
        $this->assertNotNull($this->router->match("\u{2754} Довідка"));
        foreach (['/top200full', '/gold_list', '/gold_full', '/oilbases_list', '/oilbases_full', '/72h', '/24h'] as $c) {
            $this->assertNotNull($this->router->match($c), $c);
        }
    }
}
