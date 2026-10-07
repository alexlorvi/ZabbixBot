<?php

namespace ZabbixBot\Services;

/**
 * Чисте форматування подій Zabbix для Telegram (без мережі та LangService - i18n передається параметром, тому тестується).
 *
 * @phpstan-type I18n array{severity:array<int,string>,line:string,summaryLine:string,tagsLine:string,ackLine:string,units:array{d:string,h:string,m:string}}
 */
final class EventFormatter
{
    /** Подія в єдиному вигляді з problem.get ($problem, може бути порожнім) і event.get ($info: хости, квитування, теги). */
    public static function normalize(array $problem, array $info): array
    {
        return [
            'eventid' => (string)($problem['eventid'] ?? $info['eventid'] ?? ''),
            'name' => (string)($problem['name'] ?? $info['name'] ?? ''),
            'clock' => (int)($problem['clock'] ?? $info['clock'] ?? 0),
            'severity' => (int)($problem['severity'] ?? $info['severity'] ?? 0),
            'hostName' => (string)($info['hosts'][0]['name'] ?? ''),
            'hostHost' => (string)($info['hosts'][0]['host'] ?? ''),
            'acknowledged' => !empty($problem['acknowledged'] ?? $info['acknowledged'] ?? 0),
            'acknowledges' => is_array($info['acknowledges'] ?? null) ? $info['acknowledges'] : [],
            'tags' => is_array($info['tags'] ?? null) ? $info['tags'] : [],
        ];
    }

    /** Емодзі + назва рівня критичності (або лише емодзі). */
    public static function severityLabel(int $severity, array $names, bool $emojiOnly = false): string
    {
        $label = (string)($names[$severity] ?? $severity);
        if ($emojiOnly) {
            $space = mb_strpos($label, ' ');
            return $space === false ? $label : mb_substr($label, 0, $space);
        }
        return $label;
    }

    /** "2д 3г" / "3г 15хв" / "15хв" з кількості секунд. */
    public static function duration(int $seconds, array $units): string
    {
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) return $d.$units['d'].' '.$h.$units['h'];
        if ($h > 0) return $h.$units['h'].' '.$m.$units['m'];
        return $m.$units['m'];
    }

    /** Докладний блок однієї події: критичність, час і тривалість, хост, назва, теги, квитування. */
    public static function format(array $event, array $i18n, int $now): string
    {
        $reply = sprintf($i18n['line'],
            self::severityLabel($event['severity'], $i18n['severity']),
            date('d/m/Y H:i:s', $event['clock']),
            self::duration($now - $event['clock'], $i18n['units']),
            $event['hostName'] !== '' ? $event['hostName'] : '-',
            $event['hostHost'] !== '' ? $event['hostHost'] : '-',
            $event['eventid'],
            $event['name'],
            $event['acknowledged'] ? "\u{2705}" : '');

        $tags = [];
        foreach ($event['tags'] as $tag) {
            $tags[] = $tag['tag'].(($tag['value'] ?? '') !== '' ? ':'.$tag['value'] : '');
        }
        if ($tags) {
            $reply .= sprintf($i18n['tagsLine'], implode(', ', $tags));
        }
        foreach ($event['acknowledges'] as $ack) {
            $reply .= sprintf($i18n['ackLine'],
                date('d/m/Y H:i:s', (int)$ack['clock']),
                $ack['message'] ?? '',
                $ack['author'] ?? $ack['username'] ?? $ack['userid'] ?? '');
        }
        return $reply;
    }

    /** Короткий блок події для переліку. */
    public static function summary(array $event, array $i18n): string
    {
        return sprintf($i18n['summaryLine'],
            self::severityLabel($event['severity'], $i18n['severity'], true),
            date('d/m/Y H:i:s', $event['clock']),
            $event['eventid'],
            $event['hostName'],
            $event['hostHost'],
            $event['name']);
    }
}
