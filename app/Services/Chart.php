<?php

namespace ZabbixBot\Services;

/**
 * PNG-графік без GD (лише zlib): лінії серій з коридором min..max, сітка, підписи осей вбудованим шрифтом 5x7.
 * Легенда (назви item-ів, кирилиця) - не на картинці, а в підписі до фото: кольори серій - ті самі, що в
 * емодзі-квадратах COLORS, тож легенда в тексті однозначна.
 */
final class Chart
{
    /** Кольори серій і відповідні емодзі для легенди (порядок - порядок призначення). */
    public const COLORS = [
        ["\u{1F7E9}", [67, 160, 71]],    // зелений
        ["\u{1F7E6}", [30, 136, 229]],   // синій
        ["\u{1F7E5}", [229, 57, 53]],    // червоний
        ["\u{1F7E7}", [251, 140, 0]],    // помаранчевий
        ["\u{1F7EA}", [142, 36, 170]],   // фіолетовий
        ["\u{1F7EB}", [121, 85, 72]],    // коричневий
        ["\u{1F7E8}", [253, 216, 53]],   // жовтий
    ];

    private const BG = [255, 255, 255];
    private const GRID = [228, 228, 228];
    private const AXIS = [150, 150, 150];
    private const TEXT = [60, 60, 60];
    private const SCALE = 2;       // масштаб шрифту 5x7
    private const LEFT = 120;      // поля під підписи
    private const RIGHT = 20;
    private const TOP = 16;
    private const BOTTOM = 40;

    /** Шрифт 5x7: рядки зверху вниз, '#' - піксель. Лише символи, потрібні для чисел, одиниць і часу. */
    private const FONT = [
        '0' => ['.###.', '#...#', '#..##', '#.#.#', '##..#', '#...#', '.###.'],
        '1' => ['..#..', '.##..', '..#..', '..#..', '..#..', '..#..', '.###.'],
        '2' => ['.###.', '#...#', '....#', '...#.', '..#..', '.#...', '#####'],
        '3' => ['#####', '...#.', '..#..', '...#.', '....#', '#...#', '.###.'],
        '4' => ['...#.', '..##.', '.#.#.', '#..#.', '#####', '...#.', '...#.'],
        '5' => ['#####', '#....', '####.', '....#', '....#', '#...#', '.###.'],
        '6' => ['..##.', '.#...', '#....', '####.', '#...#', '#...#', '.###.'],
        '7' => ['#####', '....#', '...#.', '..#..', '.#...', '.#...', '.#...'],
        '8' => ['.###.', '#...#', '#...#', '.###.', '#...#', '#...#', '.###.'],
        '9' => ['.###.', '#...#', '#...#', '.####', '....#', '...#.', '.##..'],
        '.' => ['.....', '.....', '.....', '.....', '.....', '.##..', '.##..'],
        ',' => ['.....', '.....', '.....', '.....', '.##..', '..#..', '.#...'],
        ':' => ['.....', '.##..', '.##..', '.....', '.##..', '.##..', '.....'],
        '-' => ['.....', '.....', '.....', '#####', '.....', '.....', '.....'],
        '+' => ['.....', '..#..', '..#..', '#####', '..#..', '..#..', '.....'],
        '/' => ['.....', '....#', '...#.', '..#..', '.#...', '#....', '.....'],
        '%' => ['##...', '##..#', '...#.', '..#..', '.#...', '#..##', '...##'],
        ' ' => ['.....', '.....', '.....', '.....', '.....', '.....', '.....'],
        'K' => ['#...#', '#..#.', '#.#..', '##...', '#.#..', '#..#.', '#...#'],
        'M' => ['#...#', '##.##', '#.#.#', '#.#.#', '#...#', '#...#', '#...#'],
        'G' => ['.###.', '#...#', '#....', '#.###', '#...#', '#...#', '.####'],
        'T' => ['#####', '..#..', '..#..', '..#..', '..#..', '..#..', '..#..'],
        'B' => ['####.', '#...#', '#...#', '####.', '#...#', '#...#', '####.'],
        'b' => ['#....', '#....', '#.##.', '##..#', '#...#', '#...#', '####.'],
        'p' => ['.....', '.....', '####.', '#...#', '####.', '#....', '#....'],
        's' => ['.....', '.....', '.###.', '#....', '.###.', '....#', '####.'],
        'm' => ['.....', '.....', '##.#.', '#.#.#', '#.#.#', '#...#', '#...#'],
        'h' => ['#....', '#....', '#.##.', '##..#', '#...#', '#...#', '#...#'],
        'd' => ['....#', '....#', '.##.#', '#..##', '#...#', '#...#', '.####'],
        'k' => ['#....', '#....', '#..#.', '#.#..', '##...', '#.#..', '#..#.'],
    ];

    /**
     * @param list<array{points:list<array{0:int,1:float,2?:float,3?:float}>}> $series точки [clock, avg, min?, max?]
     *        (history - лише значення, trends - avg/min/max); колір - за індексом серії (COLORS)
     * @param string $units одиниці осі Y (Zabbix units першого item-а з одиницями)
     * @param string $tz часовий пояс підписів часу
     * @return string PNG
     */
    public static function render(array $series, int $from, int $to, string $units, int $width = 800, int $height = 400, string $tz = ''): string
    {
        $canvas = new ChartCanvas($width, $height, self::BG);
        $plotW = $width - self::LEFT - self::RIGHT;
        $plotH = $height - self::TOP - self::BOTTOM;
        $span = max(1, $to - $from);

        // по стовпцю пікселів - min/max/avg кожної серії (як Zabbix для щільних даних)
        $columns = [];
        $lo = INF;
        $hi = -INF;
        foreach ($series as $s => $data) {
            $columns[$s] = self::bucket($data['points'], $from, $span, $plotW);
            foreach ($columns[$s] as [$min, $avg, $max]) {
                $lo = min($lo, $min);
                $hi = max($hi, $max);
            }
        }
        [$yMin, $yMax, $step] = self::yScale(is_finite($lo) ? $lo : 0.0, is_finite($hi) ? $hi : 1.0);
        $y = fn(float $v): int => self::TOP + $plotH - (int)round(($v - $yMin) / ($yMax - $yMin) * $plotH);

        // сітка і підписи Y
        for ($v = $yMin, $i = 0; $v <= $yMax + $step / 2 && $i < 20; $v += $step, $i++) {
            $py = $y($v);
            $canvas->line(self::LEFT, $py, self::LEFT + $plotW, $py, self::GRID);
            $label = self::formatValue($v, $units);
            $canvas->text(self::LEFT - 8 - self::textWidth($label), $py - (int)(3.5 * self::SCALE), $label, self::TEXT, self::SCALE);
        }
        // сітка і підписи часу
        $zone = new \DateTimeZone($tz !== '' ? $tz : date_default_timezone_get());
        [$tick, $format] = self::timeStep($span);
        $offset = $zone->getOffset(new \DateTimeImmutable('@'.$from));
        for ($t = intdiv($from + $offset, $tick) * $tick - $offset + $tick; $t < $to; $t += $tick) {
            $px = self::LEFT + (int)round(($t - $from) / $span * $plotW);
            $canvas->line($px, self::TOP, $px, self::TOP + $plotH, self::GRID);
            $label = (new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format($format);
            $canvas->text($px - intdiv(self::textWidth($label), 2), self::TOP + $plotH + 10, $label, self::TEXT, self::SCALE);
        }
        $canvas->rect(self::LEFT, self::TOP, self::LEFT + $plotW, self::TOP + $plotH, self::AXIS);

        // серії: коридор min..max світлішим кольором, лінія avg; розрив лінії, якщо даних немає довше за 3 інтервали
        foreach ($columns as $s => $cols) {
            $color = self::COLORS[$s % count(self::COLORS)][1];
            $light = array_map(fn($c) => (int)round($c + (255 - $c) * 0.65), $color);
            $gap = self::maxGap($series[$s]['points'], $span, $plotW);
            foreach ($cols as $x => [$min, $avg, $max]) {
                if ($max > $min) {
                    $canvas->line(self::LEFT + $x, $y($min), self::LEFT + $x, $y($max), $light);
                }
            }
            $prev = null;
            foreach ($cols as $x => [$min, $avg, $max]) {
                $px = self::LEFT + $x;
                $py = $y($avg);
                if ($prev !== null && $x - $prev[0] <= $gap) {
                    $canvas->line($prev[1], $prev[2], $px, $py, $color, 2);
                } else {
                    $canvas->line($px, $py, $px, $py, $color, 2);
                }
                $prev = [$x, $px, $py];
            }
        }
        return $canvas->png();
    }

    /**
     * Точки -> [стовпець => [min, avg, max]] (лише стовпці з даними, за зростанням).
     * @return array<int,array{0:float,1:float,2:float}>
     */
    public static function bucket(array $points, int $from, int $span, int $width): array
    {
        $acc = [];
        foreach ($points as $p) {
            $x = (int)floor(($p[0] - $from) / $span * $width);
            if ($x < 0 || $x >= $width) {
                continue;
            }
            $avg = (float)$p[1];
            $min = (float)($p[2] ?? $avg);
            $max = (float)($p[3] ?? $avg);
            if (!isset($acc[$x])) {
                $acc[$x] = [$min, $avg, $max, 1];
            } else {
                $acc[$x] = [min($acc[$x][0], $min), $acc[$x][1] + $avg, max($acc[$x][2], $max), $acc[$x][3] + 1];
            }
        }
        ksort($acc);
        return array_map(fn($a) => [$a[0], $a[1] / $a[3], $a[2]], $acc);
    }

    /** Найбільший розрив (у стовпцях), який ще з'єднується лінією: 3 типові інтервали між точками. */
    private static function maxGap(array $points, int $span, int $width): int
    {
        $deltas = [];
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $deltas[] = $points[$i][0] - $points[$i - 1][0];
        }
        if (!$deltas) {
            return 1;
        }
        sort($deltas);
        $median = $deltas[intdiv(count($deltas), 2)];
        return max(2, (int)ceil($median * 3 / $span * $width));
    }

    /**
     * Межі осі Y і крок сітки ("гарні" числа 1/2/5 x 10^n). Невід'ємні дані - від 0.
     * @return array{0:float,1:float,2:float}
     */
    public static function yScale(float $lo, float $hi): array
    {
        if ($lo >= 0) {
            $lo = 0.0;
        }
        if ($hi <= $lo) {
            $hi = $lo + 1;
        }
        $raw = ($hi - $lo) / 5;
        $mag = 10 ** floor(log10($raw));
        $step = $mag;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $raw) {
                $step = $m * $mag;
                break;
            }
        }
        return [floor($lo / $step) * $step, ceil($hi / $step) * $step, $step];
    }

    /** Крок міток часу і формат для тривалості періоду. @return array{0:int,1:string} */
    public static function timeStep(int $span): array
    {
        return match (true) {
            $span <= 2 * 3600 => [600, 'H:i'],
            $span <= 6 * 3600 => [3600, 'H:i'],
            $span <= 36 * 3600 => [3 * 3600, 'H:i'],
            $span <= 3 * 86400 => [12 * 3600, 'd.m H:i'],
            default => [86400, 'd.m'],
        };
    }

    /**
     * Значення з одиницями Zabbix: s -> ms/s, % як є, B/Bps - префікси 1024, інші (bps...) - 1000.
     * "1.2 Mbps", "9.7 ms", "45 %", "0".
     */
    public static function formatValue(float $v, string $units): string
    {
        if ($units === 's') {
            return abs($v) < 1 && $v != 0 ? self::num($v * 1000).' ms' : self::num($v).' s';
        }
        if ($units === '%' || $units === 'uptime' || $units === 'unixtime') {
            return self::num($v).($units === '%' ? ' %' : '');
        }
        $base = in_array($units, ['B', 'Bps'], true) ? 1024 : 1000;
        $prefix = '';
        foreach (['K', 'M', 'G', 'T'] as $p) {
            if (abs($v) < $base) {
                break;
            }
            $v /= $base;
            $prefix = $p;
        }
        $suffix = $prefix.$units;
        return self::num($v).($suffix !== '' ? ' '.$suffix : '');
    }

    /** До 3 значущих цифр, без зайвих нулів. */
    private static function num(float $v): string
    {
        $a = abs($v);
        $dec = $a == 0 || $a >= 100 ? 0 : ($a >= 10 ? 1 : ($a >= 1 ? 2 : 3));
        $s = number_format($v, $dec, '.', '');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s === '-0' ? '0' : $s;
    }

    public static function textWidth(string $text): int
    {
        return strlen($text) * 6 * self::SCALE - self::SCALE;
    }

    /** @internal для ChartCanvas */
    public static function glyph(string $ch): ?array
    {
        return self::FONT[$ch] ?? null;
    }
}
