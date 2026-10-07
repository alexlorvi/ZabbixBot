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

    /** Види каналів сповіщень: Telegram-media бота та Email. */
    private const MEDIA_KINDS = ['tg', 'email'];

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

    /** Редагує вже надіслане повідомлення в головну панель налаштувань (виклик з inline /menu). */
    public function editOpen(MessageService $messenger, UserController $user, $chatId, $messageId): void {
        [$text, $keyboard] = $this->render($user);
        $messenger->editMessage($chatId, $messageId, $text, $keyboard);
    }

    /**
     * Застосовує одну дію і перерендерює панель тим самим повідомленням.
     * @param list<string> $parts частини callback_data після "set:", напр. ['lang','ua'], ['media','tg'], ['sev','tg','3']
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

            case 'media':
                $this->editMedia($messenger, $user, $chatId, $messageId, (string)$value);
                return;

            case 'sev':
                $kind = (string)$value;
                $this->toggleSeverityBit($user, $kind, (int)($parts[2] ?? -1));
                $this->editMedia($messenger, $user, $chatId, $messageId, $kind);
                return;

            case 'close':
                $messenger->deleteMessage($chatId, $messageId);
                return;
        }

        $this->editOpen($messenger, $user, $chatId, $messageId);
    }

    private function editMedia(MessageService $messenger, UserController $user, $chatId, $messageId, string $kind): void {
        if (!in_array($kind, self::MEDIA_KINDS, true)) {
            $this->editOpen($messenger, $user, $chatId, $messageId);
            return;
        }
        [$text, $keyboard] = $this->renderMedia($user, $kind);
        $messenger->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function toggleSeverityBit(UserController $user, string $kind, int $bit): void {
        if ($bit < 0 || $bit > 5 || !in_array($kind, self::MEDIA_KINDS, true)) {
            return;
        }
        $zbxUserId = $user->getZabbixUserId();
        if ($zbxUserId === null) {
            return;
        }
        $mask = $this->mediaMask($user, $zbxUserId, $kind);
        if ($mask === null) {
            return;
        }
        $ids = $user->zabbix()->mediaTypeIdsForKind($kind);
        if (!$user->zabbix()->updateUserMediaSeverity($zbxUserId, $mask ^ (1 << $bit), $ids)) {
            mainLOG('zabbix','error','Failed to update '.$kind.' media severity for Zabbix user #'.$zbxUserId);
        }
    }

    /** Severity-маска першого media заданого виду, або null якщо у користувача такого media немає. */
    private function mediaMask(UserController $user, string $zbxUserId, string $kind): ?int {
        $ids = $user->zabbix()->mediaTypeIdsForKind($kind);
        foreach ($user->zabbix()->getUserMediasFull($zbxUserId) as $media) {
            if (in_array((string)$media['mediatypeid'], $ids, true)) {
                return (int)$media['severity'];
            }
        }
        return null;
    }

    /** @return array<string,string> */
    private function i18n(): array {
        $msg = $this->msg;
        return [
            'title' => $msg->getNested('command.settings.title'),
            'lang' => $msg->getNested('command.settings.lang'),
            'media_names' => $msg->getNested('command.settings.media_names', []),
            'media_missing' => $msg->getNested('command.settings.media_missing'),
            'severity' => $msg->getNested('command.settings.severity'),
            'menu_style' => $msg->getNested('command.settings.menu_style'),
            'menu_style_inline' => $msg->getNested('command.settings.menu_style_inline'),
            'menu_style_reply' => $msg->getNested('command.settings.menu_style_reply'),
            'severity_levels' => $msg->getNested('command.settings.severity_levels', []),
            'back' => $msg->getNested('command.settings.back'),
            'close' => $msg->getNested('command.settings.close'),
            'languageNames' => $msg->getNested('languageNames', []),
        ];
    }

    /**
     * Головна панель: збирає живі дані і делегує чистій renderFromState().
     * @return array{0:string,1:Keyboard}
     */
    public function render(UserController $user): array {
        $lang = (string)$user->getPreference('lang', 'en');
        $menuStyle = (string)$user->getPreference('menu_style', 'inline');
        $zbxUserId = $user->getZabbixUserId();
        $available = [];
        if ($zbxUserId !== null) {
            foreach (self::MEDIA_KINDS as $kind) {
                if ($this->mediaMask($user, $zbxUserId, $kind) !== null) {
                    $available[] = $kind;
                }
            }
        }
        return self::renderFromState($this->i18n(), $lang, $menuStyle, $available);
    }

    /** Підменю одного виду media (severity). @return array{0:string,1:Keyboard} */
    public function renderMedia(UserController $user, string $kind): array {
        $zbxUserId = $user->getZabbixUserId();
        $mask = $zbxUserId !== null ? $this->mediaMask($user, $zbxUserId, $kind) : null;
        return self::renderMediaFromState($this->i18n(), $kind, $mask);
    }

    /**
     * Чиста функція: головна панель (мова, стиль /menu, кнопки media-підменю, "Закрити"). Без "Назад".
     * @param array<string,mixed> $i18n
     * @param list<string> $availableMedia види media, які налаштовані у користувача
     * @return array{0:string,1:Keyboard}
     */
    public static function renderFromState(array $i18n, string $lang, string $menuStyle, array $availableMedia): array {
        $languageNames = $i18n['languageNames'];

        $text = $i18n['title']."\n\n".
            $i18n['lang'].': '.($languageNames[$lang] ?? $lang)."\n".
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

        $mediaRow = [];
        foreach ($availableMedia as $kind) {
            $mediaRow[] = Keyboard::inlineButton([
                'text' => ($i18n['media_names'][$kind] ?? $kind),
                'callback_data' => 'set:media:'.$kind,
            ]);
        }
        if ($mediaRow) {
            $keyboard->row($mediaRow);
        }

        $keyboard->row([
            Keyboard::inlineButton(['text' => $i18n['close'], 'callback_data' => 'set:close']),
        ]);

        return [$text, $keyboard];
    }

    /**
     * Чиста функція: підменю виду media - чекбокси severity та "Назад" до головної панелі.
     * @param array<string,mixed> $i18n
     * @param int|null $mask поточна severity-маска; null = такого media у користувача немає
     * @return array{0:string,1:Keyboard}
     */
    public static function renderMediaFromState(array $i18n, string $kind, ?int $mask): array {
        $name = $i18n['media_names'][$kind] ?? $kind;
        $text = $i18n['title'].' - '.$name."\n\n".($mask === null ? $i18n['media_missing'] : $i18n['severity'].':');

        $keyboard = Keyboard::make()->inline();
        if ($mask !== null) {
            foreach (array_chunk($i18n['severity_levels'], 2, true) as $pair) {
                $row = [];
                foreach ($pair as $bit => $label) {
                    $checked = (($mask >> $bit) & 1) === 1;
                    $row[] = Keyboard::inlineButton([
                        'text' => ($checked ? "\u{2705} " : "\u{2B1C} ").$label,
                        'callback_data' => 'set:sev:'.$kind.':'.$bit,
                    ]);
                }
                $keyboard->row($row);
            }
        }
        $keyboard->row([
            Keyboard::inlineButton(['text' => $i18n['back'], 'callback_data' => 'set:main']),
        ]);

        return [$text, $keyboard];
    }
}
