<?php

namespace ZabbixBot\Services;

/** Мережеві перевірки через системні утиліти (SNMP/nmap). Усі аргументи екрануються, усі виклики з timeout. */
class NetTools {

    public function __construct(private readonly array $config, private readonly string $ciscoScript) {
    }

    public function cisco(string $ip): string {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return "Щось оце $ip не схоже на IPv4";
        }
        $octets = explode('.', $ip);
        array_pop($octets);
        $gw = implode('.', $octets).'.1';
        $cmd = 'SNMP_COMMUNITY='.escapeshellarg((string)($this->config['snmp_community_cisco'] ?? ''))
            .' timeout 90 bash '.escapeshellarg($this->ciscoScript).' '.escapeshellarg($gw).' 2>&1';
        exec($cmd, $out);
        return $out ? implode("\n", $out) : 'Щось пішло не так';
    }

    /** Відповідь у HTML (parse_mode=html), вивід утиліт екранується. */
    public function apc(string $ip): string {
        $notOk = "\u{1F6AB}";
        $ok = "\u{2705}";
        $task = "\u{1F4CB}";
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'Щось оце '.self::esc($ip).' не схоже на IP';
        }
        $ipArg = escapeshellarg($ip);
        $community = escapeshellarg((string)($this->config['snmp_community_apc'] ?? 'public'));
        $pre = fn(string $body) => "\n<pre language='bash'>\n".$body."\n</pre>\n";

        exec("timeout -s INT 20 ping -AO -c 3 $ipArg 2>&1", $o1, $r1);
        $reply = $task.'<b>Test Ping</b> - '.($r1 === 0 ? $ok : $notOk."($r1)")
            .$pre($o1 ? self::esc(implode("\n", $o1)) : 'Щось пішло не так');

        exec("snmpget -t 2 -r 1 -Ovq -c $community -v 1 $ipArg SNMPv2-MIB::sysDescr.0 2>&1", $o2, $r2);
        $reply .= $task.'<b>Test SNMP</b> - '.($r2 === 0 ? $ok : $notOk."($r2)")
            .$pre($o2 ? self::esc(implode("\n", str_replace(['(', ')'], "\n", $o2))) : 'Щось пішло не так');

        exec("timeout 30 nmap -Pn -p 80,443,22 $ipArg 2>&1 | grep -v nmap.org", $o3);
        $lines = '';
        foreach ($o3 as $line) {
            $line = self::esc($line);
            if (str_contains($line, 'open')) {
                $line = $ok.$line;
            } elseif (str_contains($line, 'closed') || str_contains($line, 'filtered')) {
                $line = $notOk.$line;
            }
            $lines .= $line."\n";
        }
        return $reply.$task.'<b>Port Check</b>'.rtrim($pre($lines !== '' ? $lines : 'Щось пішло не так'), "\n");
    }

    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    }
}
