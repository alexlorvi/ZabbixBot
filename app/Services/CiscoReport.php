<?php

namespace ZabbixBot\Services;

/**
 * Звіт /cisco зі SNMP-таблиць (чисті функції: розбір виводу snmpbulkwalk і рендер HTML для Telegram).
 * Мережа - у NetTools::cisco(), тут лише дані, тому тестується без пристрою.
 *
 * Звіт: шапка (ім'я, IP, версія, аптайм), зведення по фізичних портах, інтерфейси (стан admin/oper одним значком,
 * швидкість якщо не 1G, trunk/access VLAN, IP/маска, опис, скільки в поточному стані).
 */
final class CiscoReport
{
    /** Таблиці, які опитуються (корені для snmpbulkwalk). */
    public const IF_TABLE = '.1.3.6.1.2.1.2.2.1';            // ifTable
    public const IFX_TABLE = '.1.3.6.1.2.1.31.1.1.1';        // ifXTable
    public const IP_TABLE = '.1.3.6.1.2.1.4.20.1';           // ipAddrTable
    public const TRUNK_TABLE = '.1.3.6.1.4.1.9.9.46.1.6.1.1'; // CISCO-VTP-MIB vlanTrunkPortTable
    public const VM_VLAN = '.1.3.6.1.4.1.9.9.68.1.2.2.1.2';  // CISCO-VLAN-MEMBERSHIP-MIB vmVlan (access VLAN)
    public const SYSTEM = ['.1.3.6.1.2.1.1.1.0', '.1.3.6.1.2.1.1.3.0', '.1.3.6.1.2.1.1.5.0']; // sysDescr, sysUpTime, sysName

    /** ifType, які показуємо: ethernet, gigabit, fastEther, softwareLoopback, propVirtual (SVI), tunnel, l2vlan, LAG. */
    private const TYPES = [6, 62, 117, 24, 53, 131, 135, 161];
    /** Фізичні порти (для зведення up/down). */
    private const PHYSICAL = [6, 62, 117];
    /** ifLastChange не пізніше стількох секунд після старту - вважаємо "з моменту завантаження". */
    private const BOOT_WINDOW = 600;

    /**
     * Вивід snmpbulkwalk/snmpget з -On -Oq -Oe -Ot: ".1.3.6.1...N значення" на рядок; рядки-продовження
     * (багаторядкові рядки, довгий Hex) дописуються до попереднього значення.
     * @return array<string,string> повний OID => сире значення
     */
    public static function parse(array $lines): array
    {
        $out = [];
        $last = null;
        foreach ($lines as $line) {
            if (preg_match('/^(\.[0-9.]+)\s?(.*)$/', $line, $m)) {
                $last = $m[1];
                $out[$last] = $m[2];
            } elseif ($last !== null) {
                $out[$last] .= "\n".$line;
            }
        }
        return $out;
    }

    /** Значення: "рядок" без лапок; Hex-STRING (net-snmp так віддає не-ASCII, напр. кирилицю в описах) - у UTF-8. */
    public static function value(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')) {
            return stripcslashes(substr($raw, 1, -1));
        }
        // щонайменше 2 октети: інакше числа на кшталт ifType 53 чи ifIndex 20 декодувалися б як символи
        if (preg_match('/^[0-9A-F]{2}( [0-9A-F]{2})+$/i', str_replace("\n", ' ', $raw))) {
            $bin = hex2bin(str_replace([' ', "\n"], '', $raw));
            if ($bin !== false && mb_check_encoding($bin, 'UTF-8') && !preg_match('/[\x00-\x08\x0E-\x1F]/', $bin)) {
                return $bin;
            }
        }
        return $raw;
    }

    /** Стовпці таблиці: [стовпець => [індекс => значення]] з walk-у кореня $table. */
    public static function columns(array $walk, string $table): array
    {
        $cols = [];
        $prefix = $table.'.';
        foreach ($walk as $oid => $raw) {
            if (str_starts_with($oid, $prefix)) {
                [$col, $index] = explode('.', substr($oid, strlen($prefix)), 2) + [1 => ''];
                $cols[(int)$col][$index] = $raw;
            }
        }
        return $cols;
    }

    /** VLAN з бітової маски vlanTrunkPortVlansEnabled (128 байт = VLAN 0..1023, старший біт першого байта - VLAN 0). */
    public static function vlansFromBitmap(string $hex, int $offset = 0): array
    {
        $bytes = hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', $hex)) ?: '';
        $vlans = [];
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $b = ord($bytes[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                if ($b & (0x80 >> $bit)) {
                    $vlans[] = $offset + $i * 8 + $bit;
                }
            }
        }
        return $vlans;
    }

    /** [2,3,4,5,8] -> "2-5,8"; усі 1-4094 -> "all". */
    public static function ranges(array $vlans): string
    {
        $vlans = array_values(array_filter(array_unique($vlans), fn($v) => $v >= 1 && $v <= 4094));
        sort($vlans);
        if (count($vlans) >= 1000) {
            return 'all';
        }
        $out = [];
        for ($i = 0, $n = count($vlans); $i < $n; $i++) {
            $start = $vlans[$i];
            while ($i + 1 < $n && $vlans[$i + 1] === $vlans[$i] + 1) {
                $i++;
            }
            $out[] = $start === $vlans[$i] ? (string)$start : $start.'-'.$vlans[$i];
        }
        return implode(',', $out);
    }

    /** 255.255.255.192 -> 26 */
    public static function prefix(string $mask): int
    {
        $long = ip2long($mask);
        return $long === false ? 0 : substr_count(decbin($long & 0xFFFFFFFF), '1');
    }

    /** "10" -> "10M", "1000" -> "1G", "10000" -> "10G" (ifHighSpeed у Мбіт/с). */
    public static function speed(int $mbps): string
    {
        if ($mbps <= 0) {
            return '';
        }
        return $mbps >= 1000 && $mbps % 1000 === 0 ? ($mbps / 1000).'G' : $mbps.'M';
    }

    /**
     * Модель звіту з результатів walk-ів (повні OID => сирі значення, усі разом).
     * @return array{name:string,descr:string,uptime:?int,interfaces:list<array>,addresses:list<array>}
     */
    public static function build(array $walk): array
    {
        $if = self::columns($walk, self::IF_TABLE);
        $ifx = self::columns($walk, self::IFX_TABLE);
        $ip = self::columns($walk, self::IP_TABLE);
        $trunk = self::columns($walk, self::TRUNK_TABLE);
        $vmVlan = [];
        foreach ($walk as $oid => $raw) {
            if (str_starts_with($oid, self::VM_VLAN.'.')) {
                $vmVlan[substr($oid, strlen(self::VM_VLAN) + 1)] = (int)self::value($raw);
            }
        }
        $sysUptime = isset($walk[self::SYSTEM[1]]) ? (int)self::value($walk[self::SYSTEM[1]]) : null;

        $interfaces = [];
        foreach ($if[3] ?? [] as $index => $type) { // ifType
            $type = (int)self::value($type);
            if (!in_array($type, self::TYPES, true)) {
                continue;
            }
            $name = self::value($ifx[1][$index] ?? $if[2][$index] ?? (string)$index); // ifName, інакше ifDescr
            $lastChange = isset($if[9][$index]) ? (int)self::value($if[9][$index]) : null;
            $mode = '';
            if ((int)self::value($trunk[14][$index] ?? '0') === 1) { // vlanTrunkPortDynamicStatus: trunking(1)
                $vlans = self::vlansFromBitmap($trunk[4][$index] ?? '');
                $mode = 'trunk '.($vlans ? self::ranges($vlans) : '?').(isset($trunk[5][$index]) && (int)self::value($trunk[5][$index]) !== 1 ? ' native '.(int)self::value($trunk[5][$index]) : '');
            } elseif (isset($vmVlan[$index])) {
                $mode = 'vlan '.$vmVlan[$index];
            }
            $interfaces[(int)$index] = [
                'index' => (int)$index,
                'name' => $name,
                'type' => $type,
                'physical' => in_array($type, self::PHYSICAL, true),
                'admin' => (int)self::value($if[7][$index] ?? '0'), // 1 up, 2 down, 3 testing
                'oper' => (int)self::value($if[8][$index] ?? '0'),  // 1 up, 2 down, ... 7 lowerLayerDown
                'speed' => (int)self::value($ifx[15][$index] ?? '0'),
                'alias' => trim(self::value($ifx[18][$index] ?? '')),
                // скільки в поточному стані: (sysUpTime - ifLastChange), у сотих секунди
                'since' => ($sysUptime !== null && $lastChange !== null && $lastChange > 0 && $sysUptime >= $lastChange)
                    ? intdiv($sysUptime - $lastChange, 100) : null,
                'mode' => $mode,
            ];
        }
        ksort($interfaces);

        $addresses = [];
        foreach ($ip[2] ?? [] as $addr => $index) { // ipAdEntIfIndex; індекс рядка = сама адреса
            $index = (int)self::value($index);
            $addresses[] = [
                'ip' => $addr,
                'prefix' => self::prefix(self::value($ip[3][$addr] ?? '')),
                'index' => $index,
                'name' => $interfaces[$index]['name'] ?? (isset($ifx[1][$index]) ? self::value($ifx[1][$index]) : '#'.$index),
                'alias' => $interfaces[$index]['alias'] ?? '',
                'up' => isset($interfaces[$index]) ? $interfaces[$index]['oper'] === 1 : null,
            ];
        }
        usort($addresses, fn($a, $b) => [$a['index'], ip2long($a['ip'])] <=> [$b['index'], ip2long($b['ip'])]);

        return [
            'name' => isset($walk[self::SYSTEM[2]]) ? self::value($walk[self::SYSTEM[2]]) : '',
            'descr' => isset($walk[self::SYSTEM[0]]) ? self::value($walk[self::SYSTEM[0]]) : '',
            'uptime' => $sysUptime !== null ? intdiv($sysUptime, 100) : null,
            'interfaces' => array_values($interfaces),
            'addresses' => $addresses,
        ];
    }

    /** "Cisco IOS Software [Fuji], ISR Software (...), Version 16.9.5, RELEASE ..." -> "Cisco IOS 16.9.5 (ISR)" */
    public static function shortDescr(string $descr): string
    {
        $first = trim(strtok($descr, "\r\n") ?: '');
        if (preg_match('/Version ([^,\s]+)/', $descr, $m)) {
            $platform = preg_match('/,\s*([A-Za-z0-9 -]+?) Software \(/', $first, $p) ? ' ('.trim($p[1]).')' : '';
            $os = str_contains($descr, 'NX-OS') ? 'NX-OS' : (str_contains($descr, 'IOS-XE') || str_contains($descr, 'IOS XE') ? 'IOS XE' : 'IOS');
            return 'Cisco '.$os.' '.$m[1].$platform;
        }
        return mb_strimwidth($first, 0, 80, '…');
    }

    /** Значок стану: admin і oper разом. */
    public static function stateIcon(int $admin, int $oper): string
    {
        if ($admin !== 1) {
            return "\u{26AA}";      // вимкнено адміністративно
        }
        if ($oper === 1) {
            return "\u{1F7E2}";     // up
        }
        return $oper === 2 || $oper === 7 ? "\u{1F534}" : "\u{1F7E1}"; // down / інше (testing, dormant...)
    }

    /**
     * HTML для Telegram (parse_mode=html). IP-адреси - у рядку свого інтерфейсу; адреси інтерфейсів, яких немає
     * в списку (інші ifType), - окремим розділом.
     * @param array{uptime:string,ports:string,interfaces:string,addresses:string,adminDown:string,sinceBoot:string,
     *              units:array{d:string,h:string,m:string}} $i18n
     */
    public static function render(array $model, string $ip, array $i18n): string
    {
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
        $out = [];

        $out[] = "\u{1F5A7} <b>".$esc($model['name'] !== '' ? $model['name'] : $ip).'</b> <code>'.$esc($ip).'</code>';
        $sub = [];
        if ($model['descr'] !== '') {
            $sub[] = $esc(self::shortDescr($model['descr']));
        }
        if ($model['uptime'] !== null) {
            $sub[] = sprintf($i18n['uptime'], EventFormatter::duration($model['uptime'], $i18n['units']));
        }
        if ($sub) {
            $out[] = implode(' · ', $sub);
        }

        $phys = array_filter($model['interfaces'], fn($i) => $i['physical']);
        if ($phys) {
            $up = count(array_filter($phys, fn($i) => $i['admin'] === 1 && $i['oper'] === 1));
            $off = count(array_filter($phys, fn($i) => $i['admin'] !== 1));
            $out[] = sprintf($i18n['ports'], $up, count($phys) - $up - $off, $off);
        }

        $byIndex = [];
        foreach ($model['addresses'] as $a) {
            $byIndex[$a['index']][] = '<code>'.$esc($a['ip'].'/'.$a['prefix']).'</code>';
        }

        $out[] = '';
        $out[] = '<b>'.$i18n['interfaces'].'</b>';
        // спершу фізичні порти, потім віртуальні (SVI, Loopback, Tunnel...) - через порожній рядок
        $groups = [array_filter($model['interfaces'], fn($i) => $i['physical']), array_filter($model['interfaces'], fn($i) => !$i['physical'])];
        foreach (array_values(array_filter($groups)) as $g => $group) {
            if ($g > 0) {
                $out[] = '';
            }
            foreach ($group as $i) {
                $details = [];
                if ($i['admin'] !== 1) {
                    $details[] = $i18n['adminDown'];
                } elseif ($i['oper'] === 1 && $i['physical'] && $i['speed'] > 0 && $i['speed'] !== 1000) {
                    $details[] = self::speed($i['speed']); // 1G - норма, показуємо лише відмінну швидкість
                }
                if ($i['mode'] !== '') {
                    $details[] = $esc($i['mode']);
                }
                if (isset($byIndex[$i['index']])) {
                    $details[] = implode(', ', $byIndex[$i['index']]);
                    unset($byIndex[$i['index']]);
                }
                if ($i['alias'] !== '') {
                    $details[] = '<i>'.$esc($i['alias']).'</i>';
                }
                // скільки в поточному стані - для не-up (коли впав) і для недавніх змін (< доби);
                // ifLastChange у перші хвилини після старту = стан не змінювався з моменту завантаження
                if ($i['since'] !== null && ($i['oper'] !== 1 || $i['since'] < 86400) && $i['admin'] === 1) {
                    $sinceBoot = $model['uptime'] !== null && $model['uptime'] - $i['since'] <= self::BOOT_WINDOW;
                    $details[] = ($i['oper'] === 1 ? 'up ' : 'down ')
                        .($sinceBoot ? $i18n['sinceBoot'] : EventFormatter::duration($i['since'], $i18n['units']));
                }
                $out[] = self::stateIcon($i['admin'], $i['oper']).' <code>'.$esc($i['name']).'</code>'.($details ? ' '.implode(' · ', $details) : '');
            }
        }

        $rest = array_filter($model['addresses'], fn($a) => isset($byIndex[$a['index']]));
        if ($rest) {
            $out[] = '';
            $out[] = '<b>'.$i18n['addresses'].'</b>';
            foreach ($rest as $a) {
                $out[] = '<code>'.$esc($a['ip'].'/'.$a['prefix']).'</code> '.$esc($a['name']);
            }
        }
        return implode("\n", $out);
    }
}
