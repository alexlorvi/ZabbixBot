<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\ZabbixService;
use ZabbixBot\UserController;

class HostCommand extends Command {
    protected string $name = 'host';
    protected string $pattern = '{query: .+}';
    private LangService $msg;
    protected string $description;
    private ZabbixService $zbx;

    private const HOST_BUTTONS = 10;
    private const SEVERITY_ICON = ["\u{26AA}", "\u{1F535}", "\u{1F7E1}", "\u{1F7E0}", "\u{1F534}", "\u{26D4}"];
    private const SEP = "\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}\u{2796}";

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
        $this->search($messenger, $user, $chatId, $query);
    }

    public function search(MessageService $messenger, UserController $user, $chatId, string $query): void {
        if ($query === '') {
            $messenger->sendMessage($chatId, "Використання: /host <IP або частина імені>\nНаприклад: /host 10.16.11.5 або /host Київ");
            return;
        }
        if (mb_strlen($query) < 3 || mb_strlen($query) > 64) {
            $messenger->sendMessage($chatId, 'Запит має бути від 3 до 64 символів');
            return;
        }

        $token = $user->getUserToken();
        if ($token === null) {
            return;
        }

        $hosts = $this->zbx->searchHosts($token, $query, self::HOST_BUTTONS + 1);
        if (!$hosts) {
            $messenger->sendMessage($chatId, "Нічого не знайдено за «{$query}»");
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
        $more = count($hosts) > self::HOST_BUTTONS ? "\nПоказано перші ".self::HOST_BUTTONS.', уточніть запит.' : '';
        $messenger->sendMessage($chatId, 'Знайдено кілька хостів за «'.$query.'». Оберіть:'.$more, ['reply_markup' => $keyboard]);
    }

    public function showHost(MessageService $messenger, $chatId, string $token, string $hostId): void {
        $host = $this->zbx->hostById($token, $hostId);
        if ($host === null) {
            $messenger->sendMessage($chatId, 'Хост не знайдено або немає доступу');
            return;
        }
        $problems = $this->zbx->hostProblems($token, $hostId);

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
            $buttons = [Keyboard::inlineButton(['text' => "\u{1F3D3} Ping", 'callback_data' => '/ping '.$ip])];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $buttons[] = Keyboard::inlineButton(['text' => 'Cisco', 'callback_data' => '/cisco '.$ip]);
            }
            $buttons[] = Keyboard::inlineButton(['text' => 'APC', 'callback_data' => '/apc '.$ip]);
            $markup = Keyboard::make()->inline()->row($buttons);
        }
        $messenger->sendMessage($chatId, rtrim($out), $markup !== null ? ['reply_markup' => $markup] : []);
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
