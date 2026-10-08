<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use ZabbixBot\Services\Chart;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\HostExtras;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\UsagePrompts;
use ZabbixBot\Services\ZabbixService;
use ZabbixBot\UserController;

class HostCommand extends Command {
    protected string $name = 'host';
    protected string $pattern = '{query: .+}';
    private LangService $msg;
    protected string $description;
    private ZabbixService $zbx;

    private const HOST_BUTTONS = 10;
    private const GRAPH_BUTTONS = 30;
    private const EVENTS_LIMIT = 30;
    /** Останні дані за замовчуванням (zabbix.latest_items): шаблони ключів item-ів, "*" - будь-що. */
    private const LATEST_ITEMS = ['icmpping*', 'agent.ping', 'zabbix[host,*,available]', 'system.uptime*', 'sys.uptime*',
        'system.cpu.util*', 'cpmCPUTotal5minRev', 'vm.memory.utilization*', 'system.hw.model', 'system.hw.serialnumber', 'system.sw.os*'];
    private const SEVERITY_ICON = ["\u{26AA}", "\u{1F535}", "\u{1F7E1}", "\u{1F7E0}", "\u{1F534}", "\u{26D4}"];

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
        $this->zbx = new ZabbixService();
    }

    public function handle() {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $messenger = new MessageService($this->getTelegram());
        $user = new UserController($messenger, $chatId);

        if (!$user->isUser()) {
            return;
        }

        $query = trim((string)$this->argument('query', ''));
        $this->search($messenger, $user, $chatId, $query, (int)$this->getUpdate()->getMessage()->get('message_id'));
    }

    public function search(MessageService $messenger, UserController $user, $chatId, string $query, int $userMessageId = 0): void {
        if ($query === '') {
            $usageId = $messenger->sendMessage($chatId, $this->t('usage'));
            if ($usageId !== null) {
                // якщо користувач відредагує команду - довідку буде видалено, а команду виконано (BotController)
                UsagePrompts::forBot()->remember((string)$chatId, $userMessageId, $usageId);
            }
            return;
        }
        if (mb_strlen($query) < 3 || mb_strlen($query) > 64) {
            $messenger->sendMessage($chatId, $this->t('length'));
            return;
        }

        $token = $user->getUserToken();
        if ($token === null) {
            return;
        }

        $hosts = $this->zbx->searchHosts($token, $query, self::HOST_BUTTONS + 1);
        if (!$hosts) {
            $messenger->sendMessage($chatId, sprintf($this->t('notFound'), $query));
            return;
        }

        $exact = array_values(array_filter($hosts, fn($h) => $this->isExact($h, $query)));
        if (count($hosts) === 1 || count($exact) === 1) {
            $this->showHost($messenger, $chatId, $token, (string)($exact[0]['hostid'] ?? $hosts[0]['hostid']));
            return;
        }

        $keyboard = Keyboard::make()->inline();
        foreach (array_slice($hosts, 0, self::HOST_BUTTONS) as $h) {
            $label = (string)$h['name'];
            $ip = $this->mainIp($h);
            if ($ip !== null) {
                $label .= ' ('.$ip.')';
            }
            $keyboard->row([Keyboard::inlineButton([
                'text' => mb_substr($label, 0, 60),
                'callback_data' => '/hostid'.$h['hostid'],
            ])]);
        }
        $more = count($hosts) > self::HOST_BUTTONS ? "\n".sprintf($this->t('more'), self::HOST_BUTTONS) : '';
        $messenger->sendMessage($chatId, sprintf($this->t('many'), $query).$more, ['reply_markup' => $keyboard]);
    }

    public function showHost(MessageService $messenger, $chatId, string $token, string $hostId): void {
        $host = $this->zbx->hostById($token, $hostId);
        if ($host === null) {
            $messenger->sendMessage($chatId, $this->t('noAccess'));
            return;
        }
        $problems = $this->zbx->hostProblems($token, $hostId);

        $out = "\u{1F5A5} ".$host['name'].($host['host'] !== $host['name'] ? ' ('.$host['host'].')' : '')."\n".
            $this->t('monitoring').': '.((string)$host['status'] === '0' ? $this->t('monitoringOn') : $this->t('monitoringOff'))."\n";
        foreach ($host['interfaces'] ?? [] as $if) {
            $addr = $if['ip'] !== '' && $if['ip'] !== '0.0.0.0' ? $if['ip'] : $if['dns'];
            $out .= "\u{1F310} ".$addr.((string)$if['main'] === '1' ? '' : ' ('.$this->t('extraIf').')')."\n";
        }
        foreach (['tag' => $this->t('tag'), 'location' => $this->t('location')] as $key => $title) {
            $val = trim((string)($host['inventory'][$key] ?? ''));
            if ($val !== '') {
                $out .= "$title: $val\n";
            }
        }
        $out .= "\n".($problems ? sprintf($this->t('problems'), count($problems).(count($problems) >= 15 ? '+' : ''))."\n" : $this->t('noProblems')."\n");
        foreach ($problems as $p) {
            $out .= (self::SEVERITY_ICON[(int)$p['severity']] ?? '').' '.date('d/m H:i', (int)$p['clock']).' '.$p['name'].' /ev'.$p['eventid']."\n";
        }

        $markup = Keyboard::make()->inline();
        $ip = $this->mainIp($host);
        if ($ip !== null) {
            $buttons = [Keyboard::inlineButton(['text' => "\u{1F3D3} Ping", 'callback_data' => 'net:ping:'.$ip])];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $buttons[] = Keyboard::inlineButton(['text' => 'Cisco', 'callback_data' => 'net:cisco:'.$ip]);
            }
            $buttons[] = Keyboard::inlineButton(['text' => 'APC', 'callback_data' => 'net:apc:'.$ip]);
            // чекати, поки хост почне відповідати на ping (UphostCommand)
            $buttons[] = Keyboard::inlineButton(['text' => $this->t('waitButton'), 'callback_data' => 'up:'.$ip]);
            $markup->row($buttons);
        }
        $markup->row([
            Keyboard::inlineButton(['text' => $this->t('graphsButton'), 'callback_data' => 'host:g:'.$hostId]),
            Keyboard::inlineButton(['text' => $this->t('latestButton'), 'callback_data' => 'host:d:'.$hostId]),
            Keyboard::inlineButton(['text' => $this->t('eventsButton'), 'callback_data' => 'host:e:'.$hostId]),
        ]);
        // "Оновити" - та сама картка заново (стан і проблеми)
        $markup->row([Keyboard::inlineButton(['text' => $this->msg->getNested('net.refresh'), 'callback_data' => '/hostid'.$hostId])]);
        $messenger->sendMessage($chatId, rtrim($out), ['reply_markup' => $markup]);
    }

    /**
     * Кнопки розширеної картки (callback host:...): g:<hostid> - список графіків, c:<graphid>:<період> - графік,
     * d:<hostid> - останні дані, e:<hostid> - події за 24 год. Усе - токеном користувача, тож доступ перевіряє Zabbix.
     */
    public function handleCallback(MessageService $messenger, UserController $user, $chatId, string $arg): void {
        $parts = explode(':', $arg);
        $id = $parts[1] ?? '';
        if (!ctype_digit($id)) {
            return;
        }
        $token = $user->getUserToken();
        if ($token === null) {
            return;
        }
        match ($parts[0]) {
            'g' => $this->showGraphs($messenger, $chatId, $token, $id),
            'c' => $this->sendGraph($messenger, $chatId, $token, $id, $parts[2] ?? '24h'),
            'd' => $this->showLatest($messenger, $chatId, $token, $id),
            'e' => $this->showEvents($messenger, $chatId, $token, $id),
            default => null,
        };
    }

    private function showGraphs(MessageService $messenger, $chatId, string $token, string $hostId): void {
        $host = $this->zbx->hostById($token, $hostId);
        if ($host === null) {
            $messenger->sendMessage($chatId, $this->t('noAccess'));
            return;
        }
        $graphs = $this->zbx->hostGraphs($token, $hostId);
        if (!$graphs) {
            $messenger->sendMessage($chatId, sprintf($this->t('noGraphs'), $host['name']));
            return;
        }
        $keyboard = Keyboard::make()->inline();
        foreach (array_slice($graphs, 0, self::GRAPH_BUTTONS) as $g) {
            $keyboard->row([Keyboard::inlineButton(['text' => mb_strimwidth((string)$g['name'], 0, 60, '…'), 'callback_data' => 'host:c:'.$g['graphid'].':24h'])]);
        }
        $more = count($graphs) > self::GRAPH_BUTTONS ? "\n".sprintf($this->t('graphsMore'), self::GRAPH_BUTTONS) : '';
        $messenger->sendMessage($chatId, sprintf($this->t('graphs'), $host['name']).$more, ['reply_markup' => $keyboard]);
    }

    /** Графік як PNG (Chart) з легендою в підписі і кнопками періодів. */
    private function sendGraph(MessageService $messenger, $chatId, string $token, string $graphId, string $period): void {
        $seconds = HostExtras::PERIODS[$period] ?? HostExtras::PERIODS['24h'];
        $graph = $this->zbx->graphWithItems($token, $graphId);
        if ($graph === null) {
            $messenger->sendMessage($chatId, $this->t('noAccess'));
            return;
        }
        $messenger->chatActionTyping($chatId);
        $to = time();
        $from = $to - $seconds;
        $points = $this->zbx->itemPoints($token, $graph['items'], $from, $to, $seconds >= HostExtras::TRENDS_FROM);
        $series = [];
        $units = '';
        foreach ($graph['items'] as $it) {
            if (!isset($points[$it['itemid']])) {
                continue; // нечислові item-и не малюються
            }
            $series[] = ['name' => (string)$it['name'], 'units' => (string)$it['units'], 'points' => $points[$it['itemid']]];
            if ($units === '' && $it['units'] !== '') {
                $units = (string)$it['units'];
            }
        }
        if (!array_filter($series, fn($s) => $s['points'])) {
            $messenger->sendMessage($chatId, sprintf($this->t('noData'), $graph['name']));
            return;
        }
        $labels = (array)$this->msg->getNested('command.host.periods', []);
        $buttons = [];
        foreach (array_keys(HostExtras::PERIODS) as $code) {
            $label = (string)($labels[$code] ?? $code);
            $buttons[] = ['text' => $code === $period ? '• '.$label.' •' : $label, 'callback_data' => 'host:c:'.$graphId.':'.$code];
        }
        $caption = HostExtras::caption($graph['name'], $graph['host'], (string)($labels[$period] ?? $period), $series, [
            'period' => $this->t('period'), 'last' => $this->t('legend'), 'noData' => $this->t('seriesNoData'),
        ]);
        $sent = $messenger->sendPhoto($chatId, Chart::render($series, $from, $to, $units), $caption, [
            'parse_mode' => 'html',
            'reply_markup' => json_encode(['inline_keyboard' => [$buttons]], JSON_UNESCAPED_UNICODE),
        ]);
        if ($sent === null) {
            $messenger->sendMessage($chatId, $this->t('sendFailed'));
        }
    }

    private function showLatest(MessageService $messenger, $chatId, string $token, string $hostId): void {
        $host = $this->zbx->hostById($token, $hostId);
        if ($host === null) {
            $messenger->sendMessage($chatId, $this->t('noAccess'));
            return;
        }
        $patterns = (array)ConfigService::getInstance()->getNested('zabbix.latest_items', self::LATEST_ITEMS);
        $text = HostExtras::latest($host['name'], $this->zbx->hostItems($token, $hostId), $patterns, [
            'title' => $this->t('latest'), 'none' => $this->t('noLatest'), 'ago' => $this->t('ago'),
            'units' => (array)$this->msg->getNested('main.durUnits'),
        ], time());
        $messenger->sendMessage($chatId, $text, ['parse_mode' => 'html', 'reply_markup' => $this->refreshMarkup('host:d:'.$hostId)]);
    }

    private function showEvents(MessageService $messenger, $chatId, string $token, string $hostId): void {
        $host = $this->zbx->hostById($token, $hostId);
        if ($host === null) {
            $messenger->sendMessage($chatId, $this->t('noAccess'));
            return;
        }
        $now = time();
        $text = HostExtras::events($host['name'], $this->zbx->hostEvents($token, $hostId, $now - 86400, self::EVENTS_LIMIT), [
            'title' => $this->t('events'), 'none' => $this->t('noEvents'), 'open' => $this->t('eventOpen'), 'closed' => $this->t('eventClosed'),
            'severity' => (array)$this->msg->getNested('user.severity'), 'units' => (array)$this->msg->getNested('main.durUnits'),
        ], $now);
        $messenger->sendMessage($chatId, $text, ['parse_mode' => 'html', 'reply_markup' => $this->refreshMarkup('host:e:'.$hostId)]);
    }

    private function refreshMarkup(string $data): string {
        return json_encode(['inline_keyboard' => [[['text' => $this->msg->getNested('net.refresh'), 'callback_data' => $data]]]], JSON_UNESCAPED_UNICODE);
    }

    private function t(string $key): string {
        return (string)$this->msg->getNested('command.host.'.$key);
    }

    private function isExact(array $host, string $q): bool {
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
    private function mainIp(array $host): ?string {
        $ifaces = $host['interfaces'] ?? [];
        usort($ifaces, fn($a, $b) => (int)$b['main'] <=> (int)$a['main']);
        foreach ($ifaces as $if) {
            if (filter_var($if['ip'] ?? '', FILTER_VALIDATE_IP)) {
                return $if['ip'] === '0.0.0.0' ? null : $if['ip'];
            }
        }
        return null;
    }
}
