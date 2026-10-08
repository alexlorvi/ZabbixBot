<?php

namespace ZabbixBot\Services;

/**
 * Чисте форматування розширеної картки хоста (/host): останні дані, події за період, підпис до графіка.
 * Дані - з ZabbixService, i18n передається параметром (HTML, parse_mode=html).
 */
final class HostExtras
{
    /** Періоди графіка: код у callback_data => секунди. */
    public const PERIODS = ['1h' => 3600, '24h' => 86400, '7d' => 7 * 86400];
    /** З якого періоду брати trends (годинні), а не history. */
    public const TRENDS_FROM = 3 * 86400;
    /** Підпис до фото в Telegram - до 1024 символів. */
    private const CAPTION_MAX = 1024;

    /** Чи підходить ключ item-а під шаблон з "*" (решта символів, зокрема "[", - буквально). */
    public static function keyMatches(string $pattern, string $key): bool
    {
        return preg_match('/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/', $key) === 1;
    }

    /**
     * Останні дані: item-и за шаблонами ключів (порядок - порядок шаблонів), значення з одиницями і value map,
     * давні значення - з віком.
     * @param array{title:string,none:string,ago:string,units:array} $i18n title - sprintf з назвою хоста
     */
    public static function latest(string $hostName, array $items, array $patterns, array $i18n, int $now): string
    {
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
        $picked = [];
        foreach ($items as $it) {
            foreach (array_values($patterns) as $rank => $pattern) {
                if (self::keyMatches((string)$pattern, (string)$it['key_'])) {
                    $picked[] = [$rank, (string)$it['name'], $it];
                    break;
                }
            }
        }
        usort($picked, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $out = [sprintf($i18n['title'], '<b>'.$esc($hostName).'</b>')];
        if (!$picked) {
            $out[] = $i18n['none'];
        }
        foreach ($picked as [, $name, $it]) {
            $clock = (int)($it['lastclock'] ?? 0);
            $value = $clock > 0 ? self::itemValue($it, $i18n['units']) : '—';
            $age = $clock > 0 && $now - $clock > 3600
                ? ' <i>('.sprintf($i18n['ago'], EventFormatter::duration($now - $clock, $i18n['units'])).')</i>' : '';
            $out[] = '• '.$esc($name).': <b>'.$esc($value).'</b>'.$age;
        }
        return implode("\n", $out);
    }

    /** Останнє значення item-а: value map, uptime як тривалість, числа з одиницями, текст - обрізаний. */
    public static function itemValue(array $item, array $units): string
    {
        $raw = (string)($item['lastvalue'] ?? '');
        foreach ((array)($item['valuemap']['mappings'] ?? []) as $m) {
            if ((string)($m['type'] ?? '0') === '0' && (string)$m['value'] === $raw) {
                return $m['newvalue'].' ('.$raw.')';
            }
        }
        $type = (int)($item['value_type'] ?? 4);
        if (!in_array($type, [0, 3], true) || !is_numeric($raw)) {
            return mb_strimwidth(trim($raw), 0, 120, '…');
        }
        $u = (string)($item['units'] ?? '');
        if ($u === 'uptime') {
            return EventFormatter::duration((int)$raw, $units);
        }
        if ($u === 'unixtime') {
            return date('d.m.Y H:i', (int)$raw);
        }
        return Chart::formatValue((float)$raw, $u);
    }

    /**
     * Події за період: значок рівня, час, назва, тривалість (закрита) або "триває", посилання /ev.
     * @param array{title:string,none:string,open:string,closed:string,severity:array,units:array} $i18n
     *        title - sprintf з назвою хоста; open/closed - sprintf з тривалістю
     */
    public static function events(string $hostName, array $events, array $i18n, int $now): string
    {
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
        $out = [sprintf($i18n['title'], '<b>'.$esc($hostName).'</b>')];
        if (!$events) {
            $out[] = $i18n['none'];
        }
        foreach ($events as $e) {
            $clock = (int)$e['clock'];
            $closed = (int)($e['r_clock'] ?? 0);
            $state = $closed > 0
                ? sprintf($i18n['closed'], EventFormatter::duration($closed - $clock, $i18n['units']))
                : sprintf($i18n['open'], EventFormatter::duration($now - $clock, $i18n['units']));
            $out[] = EventFormatter::severityLabel((int)$e['severity'], $i18n['severity'], true).' '.date('d/m H:i', $clock)
                .' '.$esc((string)$e['name']).' · '.$state.' /ev'.$e['eventid'];
        }
        return implode("\n", $out);
    }

    /** last/min/avg/max серії (для легенди). @return array{last:float,min:float,avg:float,max:float}|null */
    public static function stats(array $points): ?array
    {
        if (!$points) {
            return null;
        }
        $avgs = array_map(fn($p) => (float)$p[1], $points);
        return [
            'last' => (float)end($points)[1],
            'min' => min(array_map(fn($p) => (float)($p[2] ?? $p[1]), $points)),
            'avg' => array_sum($avgs) / count($avgs),
            'max' => max(array_map(fn($p) => (float)($p[3] ?? $p[1]), $points)),
        ];
    }

    /** Спільний префікс назв item-ів ("Interface Gi0/0/1(=UT=): ") - щоб не повторювати його в кожному рядку легенди. */
    public static function commonPrefix(array $names): string
    {
        if (count($names) < 2) {
            return '';
        }
        $prefix = array_shift($names);
        foreach ($names as $n) {
            while ($prefix !== '' && !str_starts_with($n, $prefix)) {
                $prefix = mb_substr($prefix, 0, -1);
            }
        }
        // лише до роздільника ("Interface Gi0/0/1(=UT=): "), не до пробілу: "Bits sent"/"Bits received" лишаються цілими
        return preg_match('/^.*[:\-]\s+/u', $prefix, $m) ? $m[0] : '';
    }

    /**
     * Підпис до графіка: назва, хост, період; рядок легенди на серію - емодзі кольору (Chart::COLORS), назва, last і min/avg/max.
     * @param list<array{name:string,units:string,points:list}> $series у порядку, в якому їх малює Chart
     * @param array{period:string,last:string,noData:string} $i18n period - sprintf з підписом періоду
     */
    public static function caption(string $graphName, string $hostName, string $periodLabel, array $series, array $i18n): string
    {
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
        $head = "\u{1F4C8} <b>".$esc($graphName).'</b>'."\n".$esc($hostName).' · '.sprintf($i18n['period'], $periodLabel);
        $prefix = self::commonPrefix(array_column($series, 'name'));
        $lines = [];
        foreach ($series as $i => $s) {
            $name = $prefix !== '' ? mb_substr($s['name'], mb_strlen($prefix)) : $s['name'];
            $st = self::stats($s['points']);
            $f = fn(float $v): string => Chart::formatValue($v, $s['units']);
            $lines[] = Chart::COLORS[$i % count(Chart::COLORS)][0].' '.$esc($name).': '
                .($st === null ? $i18n['noData'] : '<b>'.$f($st['last']).'</b> ('.$f($st['min']).' · '.$f($st['avg']).' · '.$f($st['max']).')');
        }
        $text = $head;
        if ($prefix !== '') {
            $text .= "\n".$esc(rtrim($prefix, ": \t-"));
        }
        $text .= "\n".$i18n['last'];
        foreach ($lines as $line) {
            // ліміт підпису рахується по видимому тексту, теги не входять; запас - на "…"
            if (mb_strlen(strip_tags($text."\n".$line)) > self::CAPTION_MAX - 2) {
                return $text."\n…";
            }
            $text .= "\n".$line;
        }
        return $text;
    }
}
