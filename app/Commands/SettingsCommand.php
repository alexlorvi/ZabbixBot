<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\UserController;

/** /settings - особиста панель (мова, рівень критичності сповіщень, стиль /menu). Працює через callback_query + editMessage. */
class SettingsCommand extends Command {
    protected string $name = 'settings';
    private LangService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle() {
        $chatId = $this->getUpdate()->getMessage()->getChat()->getId();
        $messenger = new MessageService($this->getTelegram());
        $user = new UserController($messenger, $chatId);

        if (!$user->isUser()) {
            return;
        }
        $this->open($messenger, $user, $chatId);
    }

    /** Шле нове повідомлення з панеллю налаштувань (виклик з /settings або reply-клавіатури). */
    public function open(MessageService $messenger, UserController $user, $chatId): void {
        [$text, $keyboard] = $this->render($user);
        $messenger->sendMessage($chatId, $text, ['reply_markup' => $keyboard]);
    }

    /** Редагує вже надіслане повідомлення в панель налаштувань (виклик з inline /menu). */
    public function editOpen(MessageService $messenger, UserController $user, $chatId, $messageId): void {
        [$text, $keyboard] = $this->render($user);
        $messenger->editMessage($chatId, $messageId, $text, $keyboard);
    }

    /**
     * Застосовує одну дію і перерендерює панель тим самим повідомленням.
     * @param list<string> $parts частини callback_data після "set:", напр. ['lang','ua'] або ['sev','3']
     */
    public function applyAndRerender(MessageService $messenger, UserController $user, $chatId, $messageId, array $parts): void {
        $action = $parts[0] ?? '';
        $value = $parts[1] ?? null;

        switch ($action) {
            case 'lang':
                if ($value !== null) {
                    $user->setPreference('lang', $value);
                    LangService::getInstance()->setLang($value);
                }
                break;

            case 'menu':
                if (in_array($value, ['inline', 'reply'], true)) {
                    $user->setPreference('menu_style', $value);
                }
                break;

            case 'sev':
                $this->toggleSeverityBit($user, (int)$value);
                break;

            case 'back':
                (new MenuCommand())->editOpenInline($messenger, $user, $chatId, $messageId);
                return;

            case 'close':
                $messenger->editMessage($chatId, $messageId, $this->msg->getNested('command.settings.closed'), Keyboard::make()->inline());
                return;
        }

        $this->editOpen($messenger, $user, $chatId, $messageId);
    }

    private function toggleSeverityBit(UserController $user, int $bit): void {
        if ($bit < 0 || $bit > 5) {
            return;
        }
        $zbxUserId = $user->getZabbixUserId();
        if ($zbxUserId === null) {
            return;
        }
        $mask = $this->currentSeverityMask($user, $zbxUserId);
        $newMask = $mask ^ (1 << $bit);
        if (!$user->zabbix()->updateUserMediaSeverity($zbxUserId, $newMask)) {
            mainLOG('zabbix','error','Failed to update media severity for Zabbix user #'.$zbxUserId);
        }
    }

    private function currentSeverityMask(UserController $user, string $zbxUserId): int {
        $medias = $user->zabbix()->getUserMediasFull($zbxUserId);
        return $medias ? (int)$medias[0]['severity'] : 0;
    }

    /**
     * Збирає живі дані (i18n-рядки, Zabbix severity) і делегує чистій renderFromState().
     * @return array{0:string,1:Keyboard}
     */
    public function render(UserController $user): array {
        $lang = (string)$user->getPreference('lang', 'en');
        $menuStyle = (string)$user->getPreference('menu_style', 'inline');
        $zbxUserId = $user->getZabbixUserId();
        $severityMask = $zbxUserId !== null ? $this->currentSeverityMask($user, $zbxUserId) : 0;

        $msg = $this->msg;
        $i18n = [
            'title' => $msg->getNested('command.settings.title'),
            'lang' => $msg->getNested('command.settings.lang'),
            'severity' => $msg->getNested('command.settings.severity'),
            'menu_style' => $msg->getNested('command.settings.menu_style'),
            'menu_style_inline' => $msg->getNested('command.settings.menu_style_inline'),
            'menu_style_reply' => $msg->getNested('command.settings.menu_style_reply'),
            'severity_levels' => $msg->getNested('command.settings.severity_levels', []),
            'back' => $msg->getNested('command.settings.back'),
            'close' => $msg->getNested('command.settings.close'),
            'languageNames' => $msg->getNested('languageNames', []),
        ];

        return self::renderFromState($i18n, $lang, $menuStyle, $severityMask);
    }

    /**
     * Чиста функція без мережі/singleton-залежностей: будує [текст, inline-клавіатура] із заданого стану.
     * @param array{title:string,lang:string,severity:string,menu_style:string,menu_style_inline:string,
     *     menu_style_reply:string,severity_levels:list<string>,back:string,close:string,languageNames:array<string,string>} $i18n
     * @return array{0:string,1:Keyboard}
     */
    public static function renderFromState(array $i18n, string $lang, string $menuStyle, int $severityMask): array {
        $languageNames = $i18n['languageNames'];
        $severityLabels = $i18n['severity_levels'];

        $text = $i18n['title']."\n\n".
            $i18n['lang'].': '.($languageNames[$lang] ?? $lang)."\n".
            $i18n['severity'].':'."\n".
            $i18n['menu_style'].': '.
            ($menuStyle === 'reply' ? $i18n['menu_style_reply'] : $i18n['menu_style_inline']);

        $keyboard = Keyboard::make()->inline();

        $langRow = [];
        foreach ($languageNames as $code => $name) {
            $prefix = $code === $lang ? "\u{2705} " : '';
            $langRow[] = Keyboard::inlineButton(['text' => $prefix.$name, 'callback_data' => 'set:lang:'.$code]);
        }
        if ($langRow) {
            $keyboard->row($langRow);
        }

        foreach (array_chunk($severityLabels, 2, true) as $pair) {
            $row = [];
            foreach ($pair as $bit => $label) {
                $checked = ((int)($severityMask >> $bit) & 1) === 1;
                $row[] = Keyboard::inlineButton([
                    'text' => ($checked ? "\u{2705} " : "\u{2B1C} ").$label,
                    'callback_data' => 'set:sev:'.$bit,
                ]);
            }
            $keyboard->row($row);
        }

        $keyboard->row([
            Keyboard::inlineButton([
                'text' => ($menuStyle === 'inline' ? "\u{2705} " : '').$i18n['menu_style_inline'],
                'callback_data' => 'set:menu:inline',
            ]),
            Keyboard::inlineButton([
                'text' => ($menuStyle === 'reply' ? "\u{2705} " : '').$i18n['menu_style_reply'],
                'callback_data' => 'set:menu:reply',
            ]),
        ]);

        $keyboard->row([
            Keyboard::inlineButton(['text' => $i18n['back'], 'callback_data' => 'set:back']),
            Keyboard::inlineButton(['text' => $i18n['close'], 'callback_data' => 'set:close']),
        ]);

        return [$text, $keyboard];
    }
}
