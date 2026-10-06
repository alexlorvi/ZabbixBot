<?php
declare(strict_types=1);

namespace ZbxBot\Tests;

use PHPUnit\Framework\TestCase;
use ZbxBot\Telegram\Messenger;
use ZbxBot\Telegram\Transport;

final class MessengerTest extends TestCase
{
    public function testChunkRespectsLimitAndKeepsText(): void
    {
        $text = implode("\n", array_map(fn($i) => "рядок $i ".str_repeat('я', 90), range(1, 200)));
        $parts = Messenger::chunk($text);
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(4096, mb_strlen($p));
        }
        $this->assertSame($text, implode("\n", $parts));
    }

    public function testChunkSplitsVeryLongLine(): void
    {
        $parts = Messenger::chunk(str_repeat('x', 9000));
        $this->assertCount(3, $parts);
        $this->assertSame(9000, mb_strlen(implode('', $parts)));
    }

    public function testHtmlPreIsBalancedAcrossChunks(): void
    {
        $text = "<b>head</b>\n<pre>\n".implode("\n", array_fill(0, 600, 'line of output'))."\n</pre>";
        $parts = Messenger::chunk($text, true);
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(4096, mb_strlen($p));
            $this->assertSame(substr_count($p, '<pre'), substr_count($p, '</pre>'), 'pre має бути збалансований у кожній частині');
        }
    }

    public function testPackBlocks(): void
    {
        $blocks = array_fill(0, 30, str_repeat('b', 300));
        $packed = Messenger::packBlocks($blocks, "\n");
        $this->assertCount(3, $packed); // по 13 блоків (13*300+12 < 4096)
        foreach ($packed as $p) {
            $this->assertLessThanOrEqual(4096, mb_strlen($p));
        }
        $this->assertSame([], Messenger::packBlocks([]));
    }

    public function testSendUsesRemoveKeyboardByDefaultAndSkipsEmpty(): void
    {
        $t = new class implements Transport {
            public array $calls = [];
            public function request(string $method, array $params): void
            {
                $this->calls[] = [$method, $params];
            }
        };
        $m = new Messenger($t);
        $m->send('1', '   ');
        $this->assertSame([], $t->calls);
        $m->send('42', 'hi', 'html');
        $this->assertSame('sendMessage', $t->calls[0][0]);
        $this->assertSame('{"remove_keyboard":true}', $t->calls[0][1]['reply_markup']);
        $this->assertSame('html', $t->calls[0][1]['parse_mode']);
        $this->assertSame('42', $t->calls[0][1]['chat_id']);
    }
}
