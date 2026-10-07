<?php

namespace ZabbixBot\Services;

/**
 * Webhook-медіатип Zabbix для alert.php - єдине джерело для `app:mediatype install` (Zabbix API) і
 * `app:mediatype export` (YAML для імпорту, docs/zbx_export_mediatypes.yaml).
 *
 * Текст сповіщень про тригери бот формує сам мовою кожного отримувача (AlertFormatter) з полів PARAMETERS.
 * Шаблони повідомлень медіатипу (TEMPLATES) - запасний варіант: discovery/autoregistration і старі запити без полів;
 * їхня мова обирається при встановленні (--lang).
 */
final class MediaTypeDefinition
{
    public const DEFAULT_NAME = 'ZabbixBot';
    public const EXPORT_VERSION = '7.4';

    /** Параметри webhook: ім'я => значення (макроси Zabbix). url/token/http_proxy - службові, в alert.php не йдуть. */
    public const PARAMETERS = [
        'url' => '',
        'token' => '',
        'http_proxy' => '',
        'sendto' => '{ALERT.SENDTO}',
        'subject' => '{ALERT.SUBJECT}',
        'message' => '{ALERT.MESSAGE}',
        'parse_mode' => 'html',
        'event_id' => '{EVENT.ID}',
        'event_value' => '{EVENT.VALUE}',
        'event_update_status' => '{EVENT.UPDATE.STATUS}',
        'event_source' => '{EVENT.SOURCE}',
        'event_name' => '{EVENT.NAME}',
        'event_severity' => '{EVENT.NSEVERITY}',
        'event_date' => '{EVENT.DATE}',
        'event_time' => '{EVENT.TIME}',
        'recovery_date' => '{EVENT.RECOVERY.DATE}',
        'recovery_time' => '{EVENT.RECOVERY.TIME}',
        'event_duration' => '{EVENT.DURATION}',
        'event_tags' => '{EVENT.TAGS}',
        'event_opdata' => '{EVENT.OPDATA}',
        'event_status' => '{EVENT.STATUS}',
        'ack_status' => '{EVENT.ACK.STATUS}',
        'host_name' => '{HOST.NAME}',
        'host_ip' => '{HOST.IP}',
        'inventory_tag' => '{INVENTORY.TAG}',
        'trigger_description' => '{TRIGGER.DESCRIPTION}',
        'trigger_url' => '{TRIGGER.URL}',
        'update_user' => '{USER.FULLNAME}',
        'update_action' => '{EVENT.UPDATE.ACTION}',
        'update_message' => '{EVENT.UPDATE.MESSAGE}',
        'update_date' => '{EVENT.UPDATE.DATE}',
        'update_time' => '{EVENT.UPDATE.TIME}',
        'uptime' => '{?last(/{HOST.HOST}/sys.uptime[sysUpTime.0])}',
    ];

    /** eventsource (0 тригери, 1 discovery, 2 autoregistration), recovery (0 проблема, 1 відновлення, 2 оновлення) */
    private const TEMPLATES = [
        'ua' => [
            [0, 0, "\u{26D4} Виявлено проблему {EVENT.DATE} о {EVENT.TIME}",
                "<b>Хост:</b> <i>{HOST.NAME} ({HOST.IP1})</i>\n<b>Назва проблеми:</b> <i>{EVENT.NAME}</i>\n<b>Рейтинг АЗК:</b> <i>{INVENTORY.TAG}</i>\n<b>Супутні теги:</b> <i>{EVENT.TAGS}</i>\n<b>Рівень обслуговування:</b> <i>{EVENT.SEVERITY}</i>\n\n<b>Операційні дані:</b> <i>{EVENT.OPDATA}</i>\n\n<b>Опис проблеми:</b>\n{TRIGGER.DESCRIPTION}\n\n{TRIGGER.URL}\n/ev{EVENT.ID}\n"],
            [0, 1, "\u{2705} Проблему усунуто {EVENT.RECOVERY.DATE} о {EVENT.RECOVERY.TIME}",
                "<b>Хост:</b> <i>{HOST.NAME} ({HOST.IP1})</i>\n<b>Назва проблеми:</b> <i>{EVENT.RECOVERY.NAME}</i>\n<b>Рейтинг АЗК:</b> <i>{INVENTORY.TAG}</i>\n<b>Супутні теги:</b> <i>{EVENT.RECOVERY.TAGS}</i>\n<b>Рівень обслуговування:</b> <i>{EVENT.SEVERITY}</i>\n\n<b>Тривалість проблеми:</b> <i>{EVENT.DURATION}</i>\n{TRIGGER.URL}\n/ev{EVENT.ID}\n"],
            [0, 2, "\u{1F4AC} Оновлення проблеми: {EVENT.NAME}",
                "{USER.FULLNAME} {EVENT.UPDATE.ACTION} {EVENT.UPDATE.DATE} {EVENT.UPDATE.TIME}\n{EVENT.UPDATE.MESSAGE}\n\nСтатус: {EVENT.STATUS}, квитовано: {EVENT.ACK.STATUS}\n/ev{EVENT.ID}\n"],
            [1, 0, 'Виявлення: {DISCOVERY.DEVICE.STATUS} {DISCOVERY.DEVICE.IPADDRESS}',
                "Правило виявлення: {DISCOVERY.RULE.NAME}\n\nIP пристрою: {DISCOVERY.DEVICE.IPADDRESS}\nDNS пристрою: {DISCOVERY.DEVICE.DNS}\nСтан пристрою: {DISCOVERY.DEVICE.STATUS}\nАптайм пристрою: {DISCOVERY.DEVICE.UPTIME}\n\nСервіс: {DISCOVERY.SERVICE.NAME}\nПорт сервісу: {DISCOVERY.SERVICE.PORT}\nСтан сервісу: {DISCOVERY.SERVICE.STATUS}\nАптайм сервісу: {DISCOVERY.SERVICE.UPTIME}\n"],
            [2, 0, 'Автореєстрація: {HOST.HOST}',
                "Ім'я хоста: {HOST.HOST}\nIP хоста: {HOST.IP}\nПорт агента: {HOST.PORT}\n"],
        ],
        'en' => [
            [0, 0, "\u{26D4} Problem detected {EVENT.DATE} at {EVENT.TIME}",
                "<b>Host:</b> <i>{HOST.NAME} ({HOST.IP1})</i>\n<b>Problem:</b> <i>{EVENT.NAME}</i>\n<b>Station rating:</b> <i>{INVENTORY.TAG}</i>\n<b>Tags:</b> <i>{EVENT.TAGS}</i>\n<b>Severity:</b> <i>{EVENT.SEVERITY}</i>\n\n<b>Operational data:</b> <i>{EVENT.OPDATA}</i>\n\n<b>Description:</b>\n{TRIGGER.DESCRIPTION}\n\n{TRIGGER.URL}\n/ev{EVENT.ID}\n"],
            [0, 1, "\u{2705} Problem resolved {EVENT.RECOVERY.DATE} at {EVENT.RECOVERY.TIME}",
                "<b>Host:</b> <i>{HOST.NAME} ({HOST.IP1})</i>\n<b>Problem:</b> <i>{EVENT.RECOVERY.NAME}</i>\n<b>Station rating:</b> <i>{INVENTORY.TAG}</i>\n<b>Tags:</b> <i>{EVENT.RECOVERY.TAGS}</i>\n<b>Severity:</b> <i>{EVENT.SEVERITY}</i>\n\n<b>Duration:</b> <i>{EVENT.DURATION}</i>\n{TRIGGER.URL}\n/ev{EVENT.ID}\n"],
            [0, 2, "\u{1F4AC} Updated problem: {EVENT.NAME}",
                "{USER.FULLNAME} {EVENT.UPDATE.ACTION} problem at {EVENT.UPDATE.DATE} {EVENT.UPDATE.TIME}.\n{EVENT.UPDATE.MESSAGE}\n\nCurrent problem status is {EVENT.STATUS}, acknowledged: {EVENT.ACK.STATUS}.\n/ev{EVENT.ID}\n"],
            [1, 0, 'Discovery: {DISCOVERY.DEVICE.STATUS} {DISCOVERY.DEVICE.IPADDRESS}',
                "Discovery rule: {DISCOVERY.RULE.NAME}\n\nDevice IP: {DISCOVERY.DEVICE.IPADDRESS}\nDevice DNS: {DISCOVERY.DEVICE.DNS}\nDevice status: {DISCOVERY.DEVICE.STATUS}\nDevice uptime: {DISCOVERY.DEVICE.UPTIME}\n\nDevice service name: {DISCOVERY.SERVICE.NAME}\nDevice service port: {DISCOVERY.SERVICE.PORT}\nDevice service status: {DISCOVERY.SERVICE.STATUS}\nDevice service uptime: {DISCOVERY.SERVICE.UPTIME}\n"],
            [2, 0, 'Autoregistration: {HOST.HOST}',
                "Host name: {HOST.HOST}\nHost IP: {HOST.IP}\nAgent port: {HOST.PORT}\n"],
        ],
    ];

    private const DESCRIPTION = <<<'TXT'
ZabbixBot: сповіщення в Telegram через бота (alert.php) / notifications to Telegram via the bot.

UA: Send to (у media користувача) = його Telegram chat id. Текст сповіщень про тригери бот формує сам мовою користувача
(/settings), з кнопками "Квитувати"/"Коментар"; відновлення приходить відповіддю на проблему.
Шаблони нижче - запасний варіант (discovery, autoregistration). Параметри url/token - з config.php бота
(alerts.url, alerts.token). Встановлення/оновлення: php console.php app:mediatype install

EN: Send to (in the user's media) = Telegram chat id. Trigger notifications are rendered by the bot in the user's
language (/settings) with "Acknowledge"/"Comment" buttons; recovery is sent as a reply to the problem.
The templates below are a fallback (discovery, autoregistration). url/token come from the bot's config.php
(alerts.url, alerts.token). Install/update: php console.php app:mediatype install
TXT;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $lang = 'ua',
        private readonly string $name = self::DEFAULT_NAME,
        private readonly string $proxy = '',
        private readonly string $scriptFile = ROOT_PATH.'/docs/zabbix-mediatype.js',
    ) {
        if (!isset(self::TEMPLATES[$this->lang])) {
            throw new \InvalidArgumentException('Unknown language "'.$this->lang.'", use: '.implode(', ', array_keys(self::TEMPLATES)));
        }
    }

    /** @return list<string> */
    public static function languages(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /** @return list<array{name:string,value:string}> */
    public function parameters(): array
    {
        $values = ['url' => $this->url, 'token' => $this->token, 'http_proxy' => $this->proxy] + self::PARAMETERS;
        $out = [];
        foreach ($values as $name => $value) {
            $out[] = ['name' => $name, 'value' => $value];
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name'])); // так само сортує експорт Zabbix
        return $out;
    }

    public function script(): string
    {
        $script = @file_get_contents($this->scriptFile);
        if ($script === false) {
            throw new \RuntimeException('Cannot read '.$this->scriptFile);
        }
        return $script;
    }

    /** Поля для mediatype.create / mediatype.update. */
    public function apiFields(bool $withName = true): array
    {
        $fields = [
            'type' => 4, // webhook
            'script' => $this->script(),
            'timeout' => '10s',
            'process_tags' => 1,
            'show_event_menu' => 0,
            'parameters' => $this->parameters(),
            'description' => self::DESCRIPTION,
            'message_templates' => array_map(fn($t) => [
                'eventsource' => $t[0], 'recovery' => $t[1], 'subject' => $t[2], 'message' => $t[3],
            ], self::TEMPLATES[$this->lang]),
        ];
        if ($withName) {
            $fields = ['name' => $this->name] + $fields;
        }
        return $fields;
    }

    /** Структура zabbix_export (як у Data collection -> Media types -> Export). */
    public function export(): array
    {
        $sources = ['TRIGGERS', 'DISCOVERY', 'AUTOREGISTRATION'];
        $modes = ['PROBLEM', 'RECOVERY', 'UPDATE'];
        return ['zabbix_export' => [
            'version' => self::EXPORT_VERSION,
            'media_types' => [[
                'name' => $this->name,
                'type' => 'WEBHOOK',
                'parameters' => $this->parameters(),
                'script' => $this->script(),
                'timeout' => '10s',
                'process_tags' => 'YES',
                'description' => self::DESCRIPTION."\n",
                'message_templates' => array_map(fn($t) => [
                    'event_source' => $sources[$t[0]], 'operation_mode' => $modes[$t[1]], 'subject' => $t[2], 'message' => $t[3],
                ], self::TEMPLATES[$this->lang]),
            ]],
        ]];
    }

    public function exportYaml(): string
    {
        return self::yaml($this->export());
    }

    /** Мінімальний YAML-емітер для структури експорту: мапи, списки мап, рядки (багаторядкові - блоком "|"). */
    public static function yaml(array $data, int $indent = 0): string
    {
        $pad = str_repeat(' ', $indent);
        $out = '';
        foreach ($data as $key => $value) {
            $isList = is_int($key);
            $prefix = $isList ? $pad.'- ' : $pad.$key.':';
            if (is_array($value)) {
                if ($isList) {
                    // перший ключ мапи - у рядку з "- ", решта з відступом +2
                    $inner = self::yaml($value, $indent + 2);
                    $out .= $pad.'- '.ltrim($inner);
                } else {
                    $out .= $prefix."\n".self::yaml($value, $indent + 2);
                }
            } else {
                $out .= ($isList ? $prefix : $prefix.' ').self::scalar((string)$value, $indent + 2)."\n";
            }
        }
        return $out;
    }

    private static function scalar(string $s, int $indent): string
    {
        if (!str_contains($s, "\n")) {
            return "'".str_replace("'", "''", $s)."'";
        }
        $pad = str_repeat(' ', $indent);
        $chomp = str_ends_with($s, "\n") ? '' : '-';
        $lines = explode("\n", rtrim($s, "\n"));
        // перший рядок з пробілу - YAML не вгадає відступ, вказуємо явно
        $indicator = str_starts_with($lines[0], ' ') ? '2' : '';
        return '|'.$indicator.$chomp."\n".implode("\n", array_map(fn($l) => $l === '' ? '' : $pad.$l, $lines));
    }
}
