<?php

namespace ZabbixBot\Services;

/** Растрове полотно RGB з виводом у PNG (zlib, без GD). */
final class ChartCanvas
{
    private string $buf;

    public function __construct(private readonly int $w, private readonly int $h, array $bg)
    {
        $this->buf = str_repeat(chr($bg[0]).chr($bg[1]).chr($bg[2]), $w * $h);
    }

    public function set(int $x, int $y, array $c): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->w || $y >= $this->h) {
            return;
        }
        $o = ($y * $this->w + $x) * 3;
        $this->buf[$o] = chr($c[0]);
        $this->buf[$o + 1] = chr($c[1]);
        $this->buf[$o + 2] = chr($c[2]);
    }

    /** Відрізок (Брезенхем), $thick - товщина в пікселях (вниз/вправо). */
    public function line(int $x0, int $y0, int $x1, int $y1, array $c, int $thick = 1): void
    {
        $dx = abs($x1 - $x0);
        $dy = -abs($y1 - $y0);
        $sx = $x0 < $x1 ? 1 : -1;
        $sy = $y0 < $y1 ? 1 : -1;
        $err = $dx + $dy;
        while (true) {
            for ($i = 0; $i < $thick; $i++) {
                for ($j = 0; $j < $thick; $j++) {
                    $this->set($x0 + $i, $y0 + $j, $c);
                }
            }
            if ($x0 === $x1 && $y0 === $y1) {
                break;
            }
            $e2 = 2 * $err;
            if ($e2 >= $dy) {
                $err += $dy;
                $x0 += $sx;
            }
            if ($e2 <= $dx) {
                $err += $dx;
                $y0 += $sy;
            }
        }
    }

    public function rect(int $x0, int $y0, int $x1, int $y1, array $c): void
    {
        $this->line($x0, $y0, $x1, $y0, $c);
        $this->line($x0, $y1, $x1, $y1, $c);
        $this->line($x0, $y0, $x0, $y1, $c);
        $this->line($x1, $y0, $x1, $y1, $c);
    }

    public function text(int $x, int $y, string $text, array $c, int $scale): void
    {
        foreach (str_split($text) as $n => $ch) {
            $glyph = Chart::glyph($ch);
            if ($glyph === null) {
                continue;
            }
            foreach ($glyph as $row => $bits) {
                for ($col = 0; $col < 5; $col++) {
                    if ($bits[$col] === '#') {
                        for ($i = 0; $i < $scale; $i++) {
                            for ($j = 0; $j < $scale; $j++) {
                                $this->set($x + ($n * 6 + $col) * $scale + $i, $y + $row * $scale + $j, $c);
                            }
                        }
                    }
                }
            }
        }
    }

    public function png(): string
    {
        $raw = '';
        $row = $this->w * 3;
        for ($y = 0; $y < $this->h; $y++) {
            $raw .= "\0".substr($this->buf, $y * $row, $row); // фільтр 0 на кожен рядок
        }
        $chunk = fn(string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $this->w, $this->h, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw, 6))
            .$chunk('IEND', '');
    }
}
