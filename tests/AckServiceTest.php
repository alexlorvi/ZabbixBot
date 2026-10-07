<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\AckService;
use ZabbixBot\Services\AlertStore;
use ZabbixBot\Services\ZabbixService;

final class AckServiceTest extends TestCase
{
    private string $dir;
    /** @var list<array{0:string,1:int,2:?string}> */
    private array $calls = [];
    private ?string $error = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-ack-'.bin2hex(random_bytes(4));
        $this->calls = [];
        $this->error = null;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function service(): AckService
    {
        return new AckService(function (string $eventId, int $action, ?string $message): ?string {
            $this->calls[] = [$eventId, $action, $message];
            return $this->error;
        });
    }

    public function testEventIdFromText(): void
    {
        $this->assertSame('123', AckService::eventIdFromText("Problem\n/ev123"));
        $this->assertSame('123', AckService::eventIdFromText('/ev123 ... /ev123'), 'той самий id двічі');
        $this->assertNull(AckService::eventIdFromText("/ev1\n/ev2"), 'перелік подій - не вгадуємо');
        $this->assertNull(AckService::eventIdFromText('no events here'));
        $this->assertNull(AckService::eventIdFromText('/evil'));
    }

    public function testEventIdForPrefersIndexOverText(): void
    {
        $store = new AlertStore($this->dir);
        $store->indexMessage('900', '42', 7);
        $this->assertSame('900', AckService::eventIdFor($store, '42', 7, 'mentions /ev111'));
        $this->assertSame('111', AckService::eventIdFor($store, '42', 8, 'mentions /ev111'));
        $this->assertNull(AckService::eventIdFor($store, '43', 7, 'plain'), 'індекс - окремо для кожного чату');
    }

    public function testAcknowledge(): void
    {
        $this->assertNull($this->service()->acknowledge('900'));
        $this->assertSame([['900', ZabbixService::ACK_ACKNOWLEDGE, null]], $this->calls);
    }

    public function testCommentTrimsAndTruncates(): void
    {
        $svc = $this->service();
        $this->assertNull($svc->comment('900', "  перевіряю  \n"));
        $this->assertSame(['900', ZabbixService::ACK_MESSAGE, 'перевіряю'], $this->calls[0]);

        $svc->comment('900', str_repeat('я', AckService::MAX_MESSAGE + 10));
        $this->assertSame(AckService::MAX_MESSAGE, mb_strlen($this->calls[1][2]));
    }

    public function testInvalidInputDoesNotCallZabbix(): void
    {
        $svc = $this->service();
        $this->assertNotNull($svc->acknowledge('9; drop'));
        $this->assertNotNull($svc->comment('900', '   '));
        $this->assertNotNull($svc->comment('abc', 'text'));
        $this->assertSame([], $this->calls);
    }

    public function testZabbixErrorIsReturned(): void
    {
        $this->error = 'No permissions [x]';
        $this->assertSame('No permissions [x]', $this->service()->acknowledge('900'));
    }

    public function testKeyboard(): void
    {
        $labels = ['ack' => 'Ack', 'comment' => 'Comment'];
        $kb = json_decode(AckService::keyboard('900', $labels), true);
        $this->assertSame(['ack:900', 'ackmsg:900'], array_column($kb['inline_keyboard'][0], 'callback_data'));
        $kb = json_decode(AckService::keyboard('900', $labels, false), true);
        $this->assertSame(['ackmsg:900'], array_column($kb['inline_keyboard'][0], 'callback_data'));
    }
}
