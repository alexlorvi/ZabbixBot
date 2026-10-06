<?php
declare(strict_types=1);

namespace ZbxBot\Bot;

use ZbxBot\Net\NetTools;
use ZbxBot\Zabbix\ZabbixException;
use ZbxBot\Zabbix\ZabbixService;

/** Обробники команд і таблиця маршрутів. */
final class Commands
{
    /** команда => [група Zabbix, детальний режим] */
    private const GROUP_COMMANDS = [
        '/top200list' => ['TOP200', false],
        '/top200full' => ['TOP200', true],
        '/gold_list' => ['Gold_AZS', false],
        '/gold_full' => ['Gold_AZS', true],
        '/oilbases_list' => ['OilBases', false],
        '/oilbases_full' => ['OilBases', true],
    ];

    private const MAX_PING_HOSTS = 5;
    private const HOST_BUTTONS = 10;
    private const SEVERITY_ICON = ["\u{26AA}", "\u{1F535}", "\u{1F7E1}", "\u{1F7E0}", "\u{1F534}", "\u{26D4}"];
    private const SEP = "\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}";
    private const SEP_FULL = "\u{3030}\u{3030}\u{3030}\u{3030}\u{3030}\u{3030}\u{3030}\u{3030}";

    public function __construct(
        private readonly ZabbixService $zbx,
        private readonly UserTokens $tokens,
        private readonly NetTools $net,
    ) {
    }

    public function register(Router $r): void
    {
        $exact = fn(string $s) => '~^'.preg_quote($s, '~').'$~u';

        $r->add('~^/start$~', [$this, 'start'], Access::Anyone);
        $r->add('~^/menu$~', [$this, 'menu']);
        foreach (self::GROUP_COMMANDS as $cmd => [$group, $full]) {
            $r->add($exact($cmd), fn(Context $c) => $this->problems($c, $full, $group));
        }
        $r->add('~^/72h$~', fn(Context $c) => $this->problems($c, true, null, 72));
        $r->add('~^/24h$~', fn(Context $c) => $this->problems($c, true, null, 24));
        $r->add($exact(Menu::BTN_DETAIL), fn(Context $c) => $this->problems($c, true));
        $r->add($exact(Menu::BTN_LIST), fn(Context $c) => $this->problems($c, false));
        $r->add($exact(Menu::BTN_HELP), [$this, 'help']);
        $r->add('~^/(\d{1,12})sec$~', [$this, 'seconds']);
        $r->add('~^/ev(\d+)$~', [$this, 'event']);
        $r->add('~^/host(?:\s+(.+))?$~su', [$this, 'host']);
        $r->add('~^/hostid(\d+)$~', [$this, 'hostById']);
        $r->add('~^/ping(\d{0,2})(?:\s+(.+))?$~su', [$this, 'ping']);
        $r->add('~^/cisco\s+(\S+)$~', [$this, 'cisco']);
        $r->add('~^/apc\s+(\S+)$~', [$this, 'apc']);
        $r->add('~^/reset$~', [$this, 'reset'], Access::Admin);
    }

    public function fallback(Context $c): void
    {
        $c->reply('Га?');
    }

    public function start(Context $c): void
    {
        $c->reply("Чат розпочато\nВаш ID - ".$c->chatId);
        if ($c->user !== null) {
            $this->tokens->issue($c->chatId, $c->user);
        }
    }

    public function menu(Context $c): void
    {
        $c->reply('Виберіть потрібну опцію:', null, Menu::markup());
    }

    public function help(Context $c): void
    {
        $c->reply(Menu::help($c->isAdmin), 'html');
    }

    public function seconds(Context $c): void
    {
        $diff = (new \DateTime('@0'))->diff(new \DateTime('@'.$c->matches[1]));
        $c->reply($diff->format('%a днів, %h годин, %i хвилин та %s секунд'));
    }

    public function event(Context $c): void
    {
        $id = $c->matches[1];
        $event = $this->zbx->event($id);
        if ($event === null || empty($event['hosts'])) {
            $c->reply("Подію $id не знайдено");
            return;
        }
        $host = $event['hosts'][0];
        $reply = "\u{23F0} ".date('d/m/Y H:i:s', (int)$event['clock'])."\n".
            "\u{1F4CC} ".$host['host']."\n".$host['name']."\n".self::SEP."\n".
            "\u{1F4C4} ".$event['name'].' '.($event['acknowledged'] ? "\u{2705}" : '')."\n".self::SEP."\n";
        foreach ($event['acknowledges'] ?? [] as $ack) {
            $reply .= "\u{1F4AC} ".date('d/m/Y H:i:s', (int)$ack['clock']).' - '.$ack['message'].' ('.$ack['username'].")\n";
        }
        $c->reply($reply);
    }

    /** /host <IP або частина імені>: 0 збігів - повідомлення, 1 - картка, кілька - кнопки. */
    public function host(Context $c): void
    {
        $query = trim($c->matches[1] ?? '');
        if ($query === '') {
            $c->reply("Використання: /host <IP або частина імені>\nНаприклад: /host 10.16.11.5 або /host Київ");
            return;
        }
        if (mb_strlen($query) < 3 || mb_strlen($query) > 64) {
            $c->reply('Запит має бути від 3 до 64 символів');
            return;
        }
        $token = $this->userToken($c);
        if ($token === null) {
            return;
        }
        try {
            $hosts = $this->zbx->searchHosts($token, $query, self::HOST_BUTTONS + 1);
        } catch (ZabbixException $e) {
            $this->zabbixDown($c, $e);
            return;
        }
        if (!$hosts) {
            $c->reply("Нічого не знайдено за «{$query}»");
            return;
        }
        $exact = array_values(array_filter($hosts, fn($h) => $this->isExact($h, $query)));
        if (count($hosts) === 1 || count($exact) === 1) {
            $this->showHost($c, $token, (string)($exact[0] ?? $hosts[0])['hostid']);
            return;
        }
        $rows = [];
        foreach (array_slice($hosts, 0, self::HOST_BUTTONS) as $h) {
            $label = (string)$h['name'];
            $ip = $this->mainIp($h);
            if ($ip !== null) {
                $label .= ' ('.$ip.')';
            }
            $rows[] = [['text' => mb_substr($label, 0, 60), 'callback_data' => '/hostid'.$h['hostid']]];
        }
        $more = count($hosts) > self::HOST_BUTTONS ? "\nПоказано перші ".self::HOST_BUTTONS.', уточніть запит.' : '';
        $c->reply('Знайдено кілька хостів за «'.$query.'». Оберіть:'.$more, null, ['inline_keyboard' => $rows]);
    }

    public function hostById(Context $c): void
    {
        $token = $this->userToken($c);
        if ($token !== null) {
            $this->showHost($c, $token, $c->matches[1]);
        }
    }

    private function showHost(Context $c, string $token, string $hostId): void
    {
        try {
            $host = $this->zbx->hostById($token, $hostId);
            $problems = $host === null ? [] : $this->zbx->hostProblems($token, $hostId);
        } catch (ZabbixException $e) {
            $this->zabbixDown($c, $e);
            return;
        }
        if ($host === null) {
            $c->reply('Хост не знайдено або немає доступу');
            return;
        }
        $out = "\u{1F5A5} ".$host['name'].($host['host'] !== $host['name'] ? ' ('.$host['host'].')' : '')."\n".
            'Моніторинг: '.((string)$host['status'] === '0' ? "увімкнено \u{2705}" : "вимкнено \u{1F6AB}")."\n";
        foreach ($host['interfaces'] ?? [] as $if) {
            $addr = $if['ip'] !== '' && $if['ip'] !== '0.0.0.0' ? $if['ip'] : $if['dns'];
            $out .= "\u{1F310} ".$addr.((string)$if['main'] === '1' ? '' : ' (дод.)')."\n";
        }
        foreach (['tag' => 'Тег', 'location' => 'Розташування'] as $key => $title) {
            $val = trim((string)($host['inventory'][$key] ?? ''));
            if ($val !== '') {
                $out .= "$title: $val\n";
            }
        }
        $out .= "\n".($problems ? 'Активні проблеми ('.count($problems).(count($problems) >= 15 ? '+' : '')."):\n" : "Активних проблем немає \u{2705}\n");
        foreach ($problems as $p) {
            $out .= (self::SEVERITY_ICON[(int)$p['severity']] ?? '').' '.date('d/m H:i', (int)$p['clock']).' '.$p['name'].' /ev'.$p['eventid']."\n";
        }
        $markup = null;
        $ip = $this->mainIp($host);
        if ($ip !== null) {
            $buttons = [['text' => "\u{1F3D3} Ping", 'callback_data' => '/ping '.$ip]];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $buttons[] = ['text' => 'Cisco', 'callback_data' => '/cisco '.$ip];
            }
            $buttons[] = ['text' => 'APC', 'callback_data' => '/apc '.$ip];
            $markup = ['inline_keyboard' => [$buttons]];
        }
        $c->reply(rtrim($out), null, $markup);
    }

    private function isExact(array $host, string $q): bool
    {
        $q = mb_strtolower($q);
        if (mb_strtolower((string)$host['name']) === $q || mb_strtolower((string)$host['host']) === $q) {
            return true;
        }
        foreach ($host['interfaces'] ?? [] as $if) {
            if ($if['ip'] === $q) {
                return true;
            }
        }
        return false;
    }

    /** IP основного інтерфейсу (або першого з адресою). */
    private function mainIp(array $host): ?string
    {
        $ifaces = $host['interfaces'] ?? [];
        usort($ifaces, fn($a, $b) => (int)$b['main'] <=> (int)$a['main']);
        foreach ($ifaces as $if) {
            if (filter_var($if['ip'] ?? '', FILTER_VALIDATE_IP)) {
                return $if['ip'] === '0.0.0.0' ? null : $if['ip'];
            }
        }
        return null;
    }

    private function userToken(Context $c): ?string
    {
        $token = $c->user !== null ? $this->tokens->tokenFor($c->chatId, $c->user) : null;
        if ($token === null) {
            $c->reply('Не вдалося отримати ваш API-токен Zabbix. Спробуйте /start');
        }
        return $token;
    }

    private function zabbixDown(Context $c, ZabbixException $e): void
    {
        $c->log->error($e->getMessage());
        $c->reply('Zabbix тимчасово недоступний. Спробуйте пізніше.');
    }

    public function ping(Context $c): void
    {
        if (empty($c->matches[2])) {
            $c->reply('Використання: /ping[1..50] <ip> [ip ...]');
            return;
        }
        $n = (int)$c->matches[1];
        $count = ($n > 0 && $n < 50) ? $n : 4;
        $hosts = preg_split('/\s+/', trim($c->matches[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_unique($hosts), 0, self::MAX_PING_HOSTS) as $host) {
            $c->reply($this->net->ping($host, $count));
        }
    }

    public function cisco(Context $c): void
    {
        $c->reply('Зачекайте. Пробую...');
        $c->reply($this->net->cisco($c->matches[1]));
    }

    public function apc(Context $c): void
    {
        $c->reply($this->net->apc($c->matches[1]), 'html');
    }

    public function reset(Context $c): void
    {
        $this->zbx->resetUserCache();
        $c->reply('Кеш користувачів і груп очищено');
    }

    /**
     * Проблеми Disaster. $full - з коментарями, інакше стислий список.
     * $olderThanHours - лише відкриті довше за цей час.
     */
    public function problems(Context $c, bool $full, ?string $group = null, ?int $olderThanHours = null): void
    {
        $groupId = null;
        if ($group !== null) {
            $groupId = $this->zbx->groupId($group);
            if ($groupId === null) {
                $c->reply("Групу $group не знайдено в Zabbix");
                return;
            }
        }
        $token = $this->userToken($c);
        if ($token === null) {
            return;
        }
        try {
            $problems = $this->zbx->userProblems(
                $token,
                [5],
                $groupId,
                $olderThanHours !== null ? time() - $olderThanHours * 3600 : null,
            );
        } catch (ZabbixException $e) {
            $this->zabbixDown($c, $e);
            return;
        }
        if (!$problems) {
            $c->reply($olderThanHours !== null
                ? "Немає інцидентів Disaster, відкритих понад $olderThanHours год."
                : 'У вас немає інцидентів в статусі Disaster');
            return;
        }
        if (!$full) {
            $c->reply('Почекайте, подумаю...');
        }
        $events = $this->zbx->events(array_column($problems, 'eventid'));
        $blocks = [];
        foreach ($problems as $problem) {
            $event = $events[(string)$problem['eventid']] ?? null;
            if ($event === null || empty($event['hosts'])) {
                continue;
            }
            $blocks[] = $full ? $this->fullBlock($problem, $event) : $this->summaryBlock($problem, $event);
        }
        if (!$blocks) {
            $c->reply('У вас немає інцидентів в статусі Disaster');
            return;
        }
        $c->replyBlocks($blocks, "\n");
        $c->reply('Всього не закрито '.count($blocks).' подій.');
    }

    private function fullBlock(array $problem, array $event): string
    {
        $host = $event['hosts'][0];
        $out = "\u{1F558} ".date('d/m/Y H:i:s', (int)$problem['clock'])."\n".
            "\u{1F516} ".$host['name'].' ('.$host['host'].")\n".self::SEP_FULL."\n".
            "\u{1F4DD} ".$problem['name'].' '.($event['acknowledged'] ? "\u{2705}" : '')."\n".self::SEP_FULL."\n";
        foreach ($event['acknowledges'] ?? [] as $ack) {
            $out .= "\n\u{1F4AC} ".date('d/m/Y H:i:s', (int)$ack['clock']).' - '.$ack['message'].' ('.$ack['username'].")\n";
        }
        return $out."\u{2693} /ev".$problem['eventid']."\n";
    }

    private function summaryBlock(array $problem, array $event): string
    {
        $name = (string)$event['hosts'][0]['name'];
        return date('d/m/Y H:i:s', (int)$problem['clock']).' - /ev'.$problem['eventid']."\n".
            (str_starts_with($name, 'Cisco') ? "\u{203C} " : '').$name."\n".self::SEP."\n";
    }
}
