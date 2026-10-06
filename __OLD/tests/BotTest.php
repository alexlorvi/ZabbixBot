<?php
declare(strict_types=1);

namespace ZbxBot\Tests;

use PHPUnit\Framework\TestCase;
use ZbxBot\Bot\Bot;
use ZbxBot\Bot\Commands;
use ZbxBot\Bot\Router;
use ZbxBot\Bot\UserTokens;
use ZbxBot\Cache\FileCache;
use ZbxBot\Cache\RateLimiter;
use ZbxBot\Cache\UpdateDeduplicator;
use ZbxBot\Log\LoggerFactory;
use ZbxBot\Net\NetTools;
use ZbxBot\Telegram\Messenger;
use ZbxBot\Telegram\Transport;
use ZbxBot\Zabbix\ZabbixService;

final class BotTest extends TestCase
{
    private string $dir;
    private object $transport;
    private ZabbixService $zbx;
    private int $updateId = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-bot-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/logs', 0700, true);
        $this->transport = new class implements Transport {
            /** @var list<array<string,mixed>> */
            public array $sent = [];
            public function request(string $method, array $params): void
            {
                $this->sent[] = $params;
            }
        };
        $this->zbx = $this->createMock(ZabbixService::class);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function bot(array $config = []): Bot
    {
        $cache = new FileCache($this->dir.'/cache');
        $logs = new LoggerFactory($this->dir.'/logs');
        $commands = new Commands($this->zbx, $this->createMock(UserTokens::class), new NetTools([], '/x'));
        $router = new Router();
        $commands->register($router);
        return new Bot($config + ['admins' => ['1']], $router, $commands, $this->zbx, new Messenger($this->transport), $logs, new RateLimiter($cache), new UpdateDeduplicator($cache), $cache);
    }

    private function update(string $text, string $chat = '1', ?int $id = null): string
    {
        return json_encode(['update_id' => $id ?? ++$this->updateId, 'message' => ['text' => $text, 'chat' => ['id' => (int)$chat, 'username' => 'u']]]);
    }

    public function testStrangerGetsOneRejectionThenSilence(): void
    {
        $this->zbx->method('findUser')->willReturn(null);
        $bot = $this->bot();
        $bot->handle($this->update('/menu', '5'), []);
        $bot->handle($this->update('/menu', '5'), []);
        $this->assertCount(1, $this->transport->sent);
        $this->assertStringContainsString('не працюю', $this->transport->sent[0]['text']);
    }

    public function testStartIsOpenToEveryone(): void
    {
        $this->zbx->method('findUser')->willReturn(null);
        $this->bot()->handle($this->update('/start', '5'), []);
        $this->assertStringContainsString('Ваш ID - 5', $this->transport->sent[0]['text']);
    }

    public function testDuplicateUpdateIsIgnored(): void
    {
        $this->zbx->method('findUser')->willReturn(['userid' => '1']);
        $bot = $this->bot();
        $bot->handle($this->update('/menu', '1', 77), []);
        $bot->handle($this->update('/menu', '1', 77), []);
        $this->assertCount(1, $this->transport->sent);
    }

    public function testSecretMismatchIs403AndNothingSent(): void
    {
        $bot = $this->bot(['webhook_secret' => 's3cret']);
        $this->assertSame(403, $bot->handle($this->update('/menu'), []));
        $this->assertSame(403, $bot->handle($this->update('/menu'), ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'wrong']));
        $this->assertSame([], $this->transport->sent);
        $this->zbx->method('findUser')->willReturn(['userid' => '1']);
        $this->assertSame(200, $bot->handle($this->update('/menu'), ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 's3cret']));
        $this->assertCount(1, $this->transport->sent);
    }

    public function testAdminOnlyReset(): void
    {
        $this->zbx->method('findUser')->willReturn(['userid' => '1']);
        $this->zbx->expects($this->once())->method('resetUserCache');
        $bot = $this->bot();
        $bot->handle($this->update('/reset', '2'), []); // не адмін
        $bot->handle($this->update('/reset', '1'), []); // адмін
        $this->assertSame('Га?', $this->transport->sent[0]['text']);
        $this->assertStringContainsString('очищено', $this->transport->sent[1]['text']);
    }

    public function testUnknownCommandAndBotSuffixAndNonText(): void
    {
        $this->zbx->method('findUser')->willReturn(['userid' => '1']);
        $bot = $this->bot();
        $bot->handle($this->update('/nope'), []);
        $this->assertSame('Га?', $this->transport->sent[0]['text']);
        $bot->handle($this->update('/menu@zbx_bot'), []);
        $this->assertStringContainsString('Виберіть', $this->transport->sent[1]['text']);
        $this->assertSame(200, $bot->handle('{"update_id":999,"edited_message":{}}', []));
        $this->assertCount(2, $this->transport->sent);
    }

    public function testRateLimitForUser(): void
    {
        $this->zbx->method('findUser')->willReturn(['userid' => '1']);
        $bot = $this->bot(['rate_limit' => [2, 60]]);
        for ($i = 0; $i < 5; $i++) {
            $bot->handle($this->update('/menu'), []);
        }
        // 2 відповіді + одне попередження
        $this->assertCount(3, $this->transport->sent);
        $this->assertStringContainsString('Забагато', $this->transport->sent[2]['text']);
    }

    public function testHandlerExceptionIsReportedNotLeaked(): void
    {
        $this->zbx->method('findUser')->willReturn(['userid' => '1']);
        $this->zbx->method('event')->willThrowException(new \RuntimeException('secret db password'));
        $this->bot()->handle($this->update('/ev5'), []);
        $this->assertStringNotContainsString('secret', $this->transport->sent[0]['text']);
        $this->assertStringContainsString('Внутрішня помилка', $this->transport->sent[0]['text']);
    }
}
