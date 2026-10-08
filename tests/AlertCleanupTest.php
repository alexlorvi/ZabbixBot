<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\AlertCleanup;
use ZabbixBot\Services\AlertStore;

final class AlertCleanupTest extends TestCase
{
    private string $dir;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/zbxbot-cleanup-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter(glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
    }

    private function store(): AlertStore
    {
        return new AlertStore($this->dir, 30, fn() => $this->now);
    }

    public function testDeletesRecoveredPairsAfterHoursRespectingUserSetting(): void
    {
        $s = $this->store();
        // 1: проблема і відновлення 13 год тому - під видалення
        $this->now -= 13 * 3600;
        $s->put('1', '42', 100);
        $s->addAlertMessage('1', '42', 101);            // оновлення (коментар)
        $s->addAlertMessage('1', '42', 102, true);      // відновлення
        // 2: відновлена 2 год тому - ще рано
        $this->now += 11 * 3600;
        $s->put('2', '42', 200);
        $s->addAlertMessage('2', '42', 201, true);
        // 3: не відновлена
        $s->put('3', '42', 300);
        // 4: інший чат з вимкненим автовидаленням
        $this->now -= 11 * 3600;
        $s->put('4', '77', 400);
        $s->addAlertMessage('4', '77', 401, true);
        $this->now += 13 * 3600;

        $deleted = [];
        $stats = AlertCleanup::run($s, $this->now, 12,
            fn(string $chat) => $chat !== '77',
            function (string $chat, int $id) use (&$deleted) { $deleted[] = "$chat:$id"; return $id !== 101; });

        $this->assertSame(['42:100', '42:101', '42:102'], $deleted);
        $this->assertSame(['events' => 2, 'deleted' => 2, 'failed' => 1, 'tooOld' => 0, 'skipped' => 1], $stats);
        $this->assertNull($s->get('1', '42'));
        $this->assertNull($s->eventForMessage('42', 100), 'index of a deleted message is gone');
        $this->assertSame('1', $s->eventForMessage('42', 101), 'not deleted - stays indexed');
        $this->assertNull($s->get('4', '77'), 'autodelete off: record forgotten, messages kept');
        $this->assertSame(200, $s->get('2', '42'));
        $this->assertSame(300, $s->get('3', '42'));

        // наступний запуск нічого не робить
        $this->assertSame(0, AlertCleanup::run($s, $this->now, 12, fn() => true, fn() => true)['events']);
    }

    public function testMessagesOlderThan48HoursAreNotDeleted(): void
    {
        $s = $this->store();
        $this->now -= 60 * 3600;            // проблема тривала дві з половиною доби
        $s->put('5', '42', 500);
        $this->now += 46 * 3600;
        $s->addAlertMessage('5', '42', 501, true);
        $this->now += 13 * 3600;

        $deleted = [];
        $stats = AlertCleanup::run($s, $this->now, 12, fn() => true, function ($c, $id) use (&$deleted) { $deleted[] = $id; return true; });
        $this->assertSame([501], $deleted);
        $this->assertSame(1, $stats['tooOld']);
    }

    public function testRecoveryWithoutStoredProblemStillCleansUp(): void
    {
        $s = $this->store();
        $this->now -= 20 * 3600;
        $s->addAlertMessage('6', '42', 600, true); // проблема не дійшла / надіслана до оновлення бота
        $this->assertNull($s->get('6', '42'), 'no reply target');
        $this->now += 20 * 3600;
        $deleted = [];
        AlertCleanup::run($s, $this->now, 12, fn() => true, function ($c, $id) use (&$deleted) { $deleted[] = $id; return true; });
        $this->assertSame([600], $deleted);
    }
}
