<?php

namespace ZabbixBot\Tests;

use PHPUnit\Framework\TestCase;
use ZabbixBot\Services\Chart;

final class ChartTest extends TestCase
{
    public function testRendersValidPng(): void
    {
        $from = 1_700_000_000;
        $points = [];
        for ($t = 0; $t < 86400; $t += 180) {
            $points[] = [$from + $t, 1000 + 500 * sin($t / 5000)];
        }
        $trend = [[$from + 3600, 5.0, 1.0, 9.0], [$from + 7200, 6.0, 2.0, 8.0]];
        $png = Chart::render([['points' => $points], ['points' => $trend], ['points' => []]], $from, $from + 86400, 'bps', 400, 200, 'UTC');

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        $ihdr = unpack('Nw/Nh/Cdepth/Ctype', substr($png, 16, 10));
        $this->assertSame(['w' => 400, 'h' => 200, 'depth' => 8, 'type' => 2], $ihdr);
        // IDAT розпаковується в h рядків по (1 + 3w) байт, з CRC
        $len = unpack('N', substr($png, 33, 4))[1];
        $this->assertSame('IDAT', substr($png, 37, 4));
        $data = substr($png, 41, $len);
        $this->assertSame(crc32('IDAT'.$data), unpack('N', substr($png, 41 + $len, 4))[1]);
        $raw = gzuncompress($data);
        $this->assertSame(200 * (1 + 400 * 3), strlen($raw));
        // перша серія (зелена) справді намальована
        $this->assertStringContainsString(implode('', array_map('chr', Chart::COLORS[0][1])), $raw);
        $this->assertStringEndsWith('IEND'.pack('N', crc32('IEND')), $png);
    }

    public function testNoDataStillRenders(): void
    {
        $this->assertSame("\x89PNG", substr(Chart::render([['points' => []]], 0, 3600, '', 100, 80, 'UTC'), 0, 4));
    }

    public function testBucketAggregatesPerColumn(): void
    {
        // 2 стовпці по 50 с: точки 0 і 10 - у стовпці 0, 60 - у стовпці 1, 200 - поза періодом
        $b = Chart::bucket([[0, 1.0], [10, 3.0], [60, 5.0, 4.0, 7.0], [200, 9.0]], 0, 100, 2);
        $this->assertSame([0 => [1.0, 2.0, 3.0], 1 => [4.0, 5.0, 7.0]], $b);
    }

    public function testYScale(): void
    {
        $this->assertSame([0.0, 20000000.0, 5000000.0], Chart::yScale(0, 19_500_000));
        $this->assertSame([0.0, 1.0, 0.2], Chart::yScale(0, 1));
        $this->assertSame([0.0, 1.0, 0.2], Chart::yScale(0, 0)); // все нулі - не ділимо на 0
        [$lo, $hi] = Chart::yScale(-3, 7);
        $this->assertLessThanOrEqual(-3, $lo);
        $this->assertGreaterThanOrEqual(7, $hi);
    }

    public function testFormatValue(): void
    {
        $this->assertSame('11.3 Mbps', Chart::formatValue(11_300_000, 'bps'));
        $this->assertSame('715 Kbps', Chart::formatValue(715_000, 'bps'));
        $this->assertSame('0 bps', Chart::formatValue(0, 'bps'));
        $this->assertSame('1.5 KB', Chart::formatValue(1536, 'B'));
        $this->assertSame('9.7 ms', Chart::formatValue(0.0097, 's'));
        $this->assertSame('2.5 s', Chart::formatValue(2.5, 's'));
        $this->assertSame('45 %', Chart::formatValue(45, '%'));
        $this->assertSame('0.99', Chart::formatValue(0.99, ''));
        $this->assertSame('1.2 K', Chart::formatValue(1200, ''));
    }

    public function testTimeStep(): void
    {
        $this->assertSame([600, 'H:i'], Chart::timeStep(3600));
        $this->assertSame([3 * 3600, 'H:i'], Chart::timeStep(86400));
        $this->assertSame([86400, 'd.m'], Chart::timeStep(7 * 86400));
    }
}
