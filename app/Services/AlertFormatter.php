<?php

namespace ZabbixBot\Services;

/**
 * Текст сповіщення Zabbix мовою отримувача (HTML) зі структурованих полів медіатипу (MediaTypeDefinition::PARAMETERS).
 * Чиста функція: i18n передається параметром, тому тестується без LangService.
 *
 * Шаблон - список рядків з {плейсхолдерами}; рядок, усі плейсхолдери якого порожні, пропускається
 * (напр. немає opdata чи URL тригера). Значення з Zabbix екрануються для HTML.
 */
final class AlertFormatter
{
    /**
     * @param array<string,mixed> $p поля запиту alert.php
     * @param string $mode problem|recovery|update
     * @param array{problem:list<string>,recovery:list<string>,update:list<string>,severity:array<int,string>,
     *              units:array{d:string,h:string,m:string},status?:array<string,string>,ack?:array<string,string>,
     *              actions?:array<string,string>} $i18n
     * @return string|null null - полів немає (не тригер або старий медіатип), використати subject/message з Zabbix
     */
    public static function render(array $p, string $mode, array $i18n): ?string
    {
        $name = trim((string)($p['event_name'] ?? ''));
        if ((string)($p['event_source'] ?? '') !== '0' || $name === '' || !isset($i18n[$mode])) {
            return null;
        }
        $f = fn(string $key): string => trim((string)($p[$key] ?? ''));
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');

        $severity = (int)$f('event_severity');
        $host = $f('host_name');
        if ($host !== '' && $f('host_ip') !== '') {
            $host .= ' ('.$f('host_ip').')';
        }
        $uptime = ctype_digit($f('uptime')) ? EventFormatter::duration((int)$f('uptime'), $i18n['units']) : '';

        $values = [
            'icon' => $f('event_severity') !== '' ? EventFormatter::severityLabel($severity, $i18n['severity'], true) : '',
            'severity' => $f('event_severity') !== '' ? $esc(EventFormatter::severityLabel($severity, $i18n['severity'])) : '',
            'name' => $esc($name),
            'host' => $esc($host),
            'date' => $esc($f('event_date')),
            'time' => $esc($f('event_time')),
            'recovery_date' => $esc($f('recovery_date')),
            'recovery_time' => $esc($f('recovery_time')),
            'duration' => $esc($f('event_duration')),
            'tags' => $esc($f('event_tags')),
            'opdata' => $esc($f('event_opdata')),
            'inventory_tag' => $esc($f('inventory_tag')),
            'description' => $esc($f('trigger_description')),
            'url' => $esc($f('trigger_url')),
            'uptime' => $esc($uptime),
            'event_id' => preg_replace('/[^0-9]/', '', $f('event_id')),
            'user' => $esc($f('update_user')),
            'action' => $esc(strtr($f('update_action'), (array)($i18n['actions'] ?? []))),
            'message' => $esc($f('update_message')),
            'update_date' => $esc($f('update_date')),
            'update_time' => $esc($f('update_time')),
            'status' => $esc((string)(($i18n['status'] ?? [])[$f('event_status')] ?? $f('event_status'))),
            'ack' => $esc((string)(($i18n['ack'] ?? [])[$f('ack_status')] ?? $f('ack_status'))),
        ];

        $out = [];
        foreach ($i18n[$mode] as $line) {
            preg_match_all('/\{([a-z_]+)\}/', $line, $m);
            $filled = array_filter($m[1], fn($k) => ($values[$k] ?? '') !== '');
            if ($m[1] && !$filled) {
                continue;
            }
            $out[] = preg_replace_callback('/\{([a-z_]+)\}/', fn($x) => $values[$x[1]] ?? '', $line);
        }
        // кілька порожніх рядків поспіль (після пропущених) - в один
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
    }
}
