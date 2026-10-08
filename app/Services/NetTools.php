<?php

namespace ZabbixBot\Services;

/** Мережеві перевірки через системні утиліти (SNMP/nmap). Усі аргументи екрануються, усі виклики з timeout. */
class NetTools {

    /** @var callable(string):list<string> запуск команди оболонки -> рядки виводу (у тестах підміняється) */
    private $run;

    /**
     * @param array $text i18n (LangService net.*): bad_ip/bad_ipv4 (sprintf з адресою), failed, cisco.* (див. CiscoReport::render), units
     */
    public function __construct(private readonly array $config, private readonly array $text = [], ?callable $run = null) {
        $this->run = $run ?? function (string $cmd): array {
            exec($cmd, $out);
            return $out;
        };
    }

    /** Інстанс з config net.* і текстами поточної мови. */
    public static function fromConfig(): self {
        $lang = LangService::getInstance();
        return new self(
            (array)ConfigService::getInstance()->getNested('net', []),
            (array)$lang->getNested('net', []) + ['units' => (array)$lang->getNested('main.durUnits', ['d' => 'd', 'h' => 'h', 'm' => 'm'])],
        );
    }

    private function t(string $key, string $arg = ''): string {
        $defaults = ['bad_ip' => '%s does not look like an IP', 'bad_ipv4' => '%s does not look like an IPv4', 'failed' => 'Something went wrong'];
        return sprintf((string)($this->text[$key] ?? $defaults[$key]), $arg);
    }

    /**
     * Стан Cisco (HTML, parse_mode=html) по SNMP v2c: інтерфейси, IP-адреси, trunk/access VLAN.
     * Опитується шлюз підмережі вказаного IP (x.x.x.1) - /cisco викликають з IP хоста АЗК.
     */
    public function cisco(string $ip): string {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return htmlspecialchars($this->t('bad_ipv4', $ip), ENT_NOQUOTES, 'UTF-8');
        }
        $c = (array)($this->text['cisco'] ?? []) + [
            'noCommunity' => 'SNMP community for Cisco is not configured (net.snmp_community_cisco)',
            'noSnmp' => 'No SNMP response from %s',
            'uptime' => 'uptime %s', 'ports' => "Ports: %d up (\u{1F7E2}) · %d down (\u{1F534}) · %d disabled (\u{26AA})",
            'sinceBoot' => 'since boot',
            'interfaces' => 'Interfaces', 'addresses' => 'Other IP addresses', 'adminDown' => 'disabled',
        ];
        $community = (string)($this->config['snmp_community_cisco'] ?? '');
        if ($community === '') {
            return htmlspecialchars($c['noCommunity'], ENT_NOQUOTES, 'UTF-8');
        }
        $octets = explode('.', $ip);
        array_pop($octets);
        $gw = implode('.', $octets).'.1';

        // -On числові OID, -Oq без типів, -Oe enum числом, -Ot timeticks числом; опції - до адреси хоста
        $opts = '-v2c -c '.escapeshellarg($community).' -On -Oq -Oe -Ot -t 2 -r 1';
        $walk = CiscoReport::parse(($this->run)('timeout 10 snmpget '.$opts.' '.escapeshellarg($gw).' '.implode(' ', CiscoReport::SYSTEM).' 2>&1'));
        if (!isset($walk[CiscoReport::SYSTEM[1]])) {
            return sprintf(htmlspecialchars($c['noSnmp'], ENT_NOQUOTES, 'UTF-8'), $gw);
        }
        $roots = [
            CiscoReport::IF_TABLE => '', CiscoReport::IFX_TABLE => '', CiscoReport::IP_TABLE => '',
            CiscoReport::TRUNK_TABLE.'.14' => '', CiscoReport::TRUNK_TABLE.'.5' => '',
            CiscoReport::TRUNK_TABLE.'.4' => ' -Ox', // бітова маска VLAN - завжди hex
            CiscoReport::VM_VLAN => '',
        ];
        foreach ($roots as $root => $extra) {
            $walk += CiscoReport::parse(($this->run)('timeout 30 snmpbulkwalk '.$opts.$extra.' '.escapeshellarg($gw).' '.escapeshellarg($root).' 2>&1'));
        }
        return CiscoReport::render(CiscoReport::build($walk), $gw, $c + ['units' => (array)($this->text['units'] ?? ['d' => 'd', 'h' => 'h', 'm' => 'm'])]);
    }

    /** Відповідь у HTML (parse_mode=html), вивід утиліт екранується. */
    public function apc(string $ip): string {
        $notOk = "\u{1F6AB}";
        $ok = "\u{2705}";
        $task = "\u{1F4CB}";
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->t('bad_ip', self::esc($ip));
        }
        $ipArg = escapeshellarg($ip);
        $community = escapeshellarg((string)($this->config['snmp_community_apc'] ?? 'public'));
        $pre = fn(string $body) => "\n<pre language='bash'>\n".$body."\n</pre>\n";

        exec("timeout -s INT 20 ping -AO -c 3 $ipArg 2>&1", $o1, $r1);
        $reply = $task.'<b>Test Ping</b> - '.($r1 === 0 ? $ok : $notOk."($r1)")
            .$pre($o1 ? self::esc(implode("\n", $o1)) : $this->t('failed'));

        exec("snmpget -t 2 -r 1 -Ovq -c $community -v 1 $ipArg SNMPv2-MIB::sysDescr.0 2>&1", $o2, $r2);
        $reply .= $task.'<b>Test SNMP</b> - '.($r2 === 0 ? $ok : $notOk."($r2)")
            .$pre($o2 ? self::esc(implode("\n", str_replace(['(', ')'], "\n", $o2))) : $this->t('failed'));

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
        return $reply.$task.'<b>Port Check</b>'.rtrim($pre($lines !== '' ? $lines : $this->t('failed')), "\n");
    }

    private static function esc(string $s): string {
        return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    }
}
