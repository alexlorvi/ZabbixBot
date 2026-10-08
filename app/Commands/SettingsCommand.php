<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\UserController;

/**
 * /settings - особиста панель (мова, стиль /menu, рівні критичності кожного способу сповіщення).
 * Працює через callback_query + editMessage. Способи сповіщення (media), назви їхніх типів і поточні рівні беруться
 * з Zabbix і змінюються там же (user.update); назви медіатипів кешуються (ZabbixService::mediaTypeNames()).
 */
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

    /** Редагує вже надіслане повідомлення в головну панель налаштувань (виклик з inline /menu). */
    public function editOpen(MessageService $messenger, UserController $user, $chatId, $messageId): void {
        [$text, $keyboard] = $this->render($user);
        $messenger->editMessage($chatId, $messageId, $text, $keyboard);
    }

    /**
     * Застосовує одну дію і перерендерює панель тим самим повідомленням.
     * @param list<string> $parts частини callback_data після "set:", напр. ['lang','ua'], ['media','<mediaid>'], ['sev','<mediaid>','3']
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
                // після user.update mediaid можуть змінитися - підменю перебудовується за новими
                $newId = $this->toggleSeverityBit($user, (string)$value, (int)($parts[2] ?? -1));
                $this->editMedia($messenger, $user, $chatId, $messageId, $newId ?? (string)$value);
                return;

            case 'close':
                $messenger->deleteMessage($chatId, $messageId);
                return;
        }

        $this->editOpen($messenger, $user, $chatId, $messageId);
    }

    private function editMedia(MessageService $messenger, UserController $user, $chatId, $messageId, string $mediaId): void {
        [$text, $keyboard] = $this->renderMedia($user, $mediaId);
        $messenger->editMessage($chatId, $messageId, $text, $keyboard);
    }

    /**
     * Перемикає один рівень у severity-масці media на сервері. userid - лише автентифікованого чату, не з callback_data.
     * @return string|null mediaid цього ж media після оновлення (Zabbix перестворює media), null - не вдалося
     */
    private function toggleSeverityBit(UserController $user, string $mediaId, int $bit): ?string {
        $zbxUserId = $user->getZabbixUserId();
        if ($bit < 0 || $bit > 5 || $zbxUserId === null) {
            return null;
        }
        $media = self::findMedia($user->zabbix()->getUserMediasFull($zbxUserId), $mediaId);
        if ($media === null || !empty($media['provisioned'])) {
            return null;
        }
        $newMask = (int)$media['severity'] ^ (1 << $bit);
        if (!$user->zabbix()->updateUserMediaSeverity($zbxUserId, $mediaId, $newMask)) {
            mainLOG('zabbix','error','Failed to update media #'.$mediaId.' severity for Zabbix user #'.$zbxUserId);
            return null;
        }
        // той самий media (тип + адреса) у свіжому списку
        foreach ($user->zabbix()->getUserMediasFull($zbxUserId) as $m) {
            if ((string)$m['mediatypeid'] === (string)$media['mediatypeid'] && $m['sendto'] == $media['sendto']) {
                return (string)$m['mediaid'];
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $medias */
    private static function findMedia(array $medias, string $mediaId): ?array {
        foreach ($medias as $m) {
            if ((string)$m['mediaid'] === $mediaId) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Чиста функція: media користувача (як з user.get) -> пункти панелі.
     * Підпис - назва медіатипу з сервера; якщо медіатипів одного типу кілька - з адресою; вимкнені - з позначкою.
     * @param list<array<string,mixed>> $medias
     * @param array<string,string> $typeNames mediatypeid => name
     * @return list<array{id:string,label:string,mask:int,active:bool,provisioned:bool}>
     */
    public static function mediaEntries(array $medias, array $typeNames): array {
        $perType = array_count_values(array_map(fn($m) => (string)$m['mediatypeid'], $medias));
        $out = [];
        foreach ($medias as $m) {
            $type = (string)$m['mediatypeid'];
            $label = $typeNames[$type] ?? '#'.$type;
            if ($perType[$type] > 1) {
                $sendto = is_array($m['sendto']) ? implode(', ', $m['sendto']) : (string)$m['sendto'];
                $label .= ' ('.mb_strimwidth($sendto, 0, 24, '…').')';
            }
            $out[] = [
                'id' => (string)$m['mediaid'],
                'label' => $label,
                'mask' => (int)$m['severity'],
                'active' => (string)($m['active'] ?? '0') === '0', // у Zabbix 0 = увімкнено
                'provisioned' => !empty($m['provisioned']),
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function i18n(): array {
        $msg = $this->msg;
        return [
            'title' => $msg->getNested('command.settings.title'),
            'lang' => $msg->getNested('command.settings.lang'),
            'media' => $msg->getNested('command.settings.media'),
            'media_disabled' => $msg->getNested('command.settings.media_disabled'),
            'media_provisioned' => $msg->getNested('command.settings.media_provisioned'),
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

    /** Пункти media автентифікованого користувача з сервера. @return list<array{id:string,label:string,mask:int,active:bool,provisioned:bool}> */
    private function userMedia(UserController $user): array {
        $zbxUserId = $user->getZabbixUserId();
        if ($zbxUserId === null) {
            return [];
        }
        return self::mediaEntries($user->zabbix()->getUserMediasFull($zbxUserId), $user->zabbix()->mediaTypeNames());
    }

    /**
     * Головна панель: збирає живі дані і делегує чистій renderFromState().
     * @return array{0:string,1:Keyboard}
     */
    public function render(UserController $user): array {
        $lang = (string)$user->getPreference('lang', 'en');
        $menuStyle = (string)$user->getPreference('menu_style', 'inline');
        return self::renderFromState($this->i18n(), $lang, $menuStyle, $this->userMedia($user));
    }

    /** Підменю одного media (severity). @return array{0:string,1:Keyboard} */
    public function renderMedia(UserController $user, string $mediaId): array {
        $entry = null;
        foreach ($this->userMedia($user) as $e) {
            if ($e['id'] === $mediaId) {
                $entry = $e;
            }
        }
        return self::renderMediaFromState($this->i18n(), $entry);
    }

    /** Назви увімкнених рівнів маски через кому, або "-". */
    private static function levelsText(array $levels, int $mask): string {
        $on = [];
        foreach ($levels as $bit => $label) {
            if ((($mask >> $bit) & 1) === 1) {
                $on[] = $label;
            }
        }
        return $on ? implode(', ', $on) : '-';
    }

    /**
     * Чиста функція: головна панель (мова, стиль /menu, способи сповіщення з поточними рівнями, "Закрити"). Без "Назад".
     * @param array<string,mixed> $i18n
     * @param list<array{id:string,label:string,mask:int,active:bool,provisioned:bool}> $media mediaEntries()
     * @return array{0:string,1:Keyboard}
     */
    public static function renderFromState(array $i18n, string $lang, string $menuStyle, array $media): array {
        $languageNames = $i18n['languageNames'];

        $text = $i18n['title']."\n\n".
            $i18n['lang'].': '.($languageNames[$lang] ?? $lang)."\n".
            $i18n['menu_style'].': '.
            ($menuStyle === 'reply' ? $i18n['menu_style_reply'] : $i18n['menu_style_inline']);
        if ($media) {
            $text .= "\n\n".$i18n['media'].':';
            foreach ($media as $m) {
                $text .= "\n\u{2022} ".$m['label'].($m['active'] ? '' : ' ('.$i18n['media_disabled'].')').': '
                    .self::levelsText($i18n['severity_levels'], $m['mask']);
            }
        }

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

        foreach (array_chunk($media, 2) as $pair) {
            $keyboard->row(array_map(fn($m) => Keyboard::inlineButton([
                'text' => "\u{1F514} ".$m['label'],
                'callback_data' => 'set:media:'.$m['id'],
            ]), $pair));
        }

        $keyboard->row([
            Keyboard::inlineButton(['text' => $i18n['close'], 'callback_data' => 'set:close']),
        ]);

        return [$text, $keyboard];
    }

    /**
     * Чиста функція: підменю одного media - чекбокси severity, "Назад" і "Закрити". Provisioned (LDAP) - лише перегляд.
     * @param array<string,mixed> $i18n
     * @param array{id:string,label:string,mask:int,active:bool,provisioned:bool}|null $media null - media вже немає
     * @return array{0:string,1:Keyboard}
     */
    public static function renderMediaFromState(array $i18n, ?array $media): array {
        $keyboard = Keyboard::make()->inline();
        if ($media === null) {
            $text = $i18n['title']."\n\n".$i18n['media_missing'];
        } else {
            $text = $i18n['title'].' - '.$media['label']."\n\n"
                .($media['active'] ? '' : $i18n['media_disabled']."\n")
                .$i18n['severity'].':';
            if ($media['provisioned']) {
                $text .= ' '.self::levelsText($i18n['severity_levels'], $media['mask'])."\n\n".$i18n['media_provisioned'];
            } else {
                foreach (array_chunk($i18n['severity_levels'], 2, true) as $pair) {
                    $row = [];
                    foreach ($pair as $bit => $label) {
                        $checked = (($media['mask'] >> $bit) & 1) === 1;
                        $row[] = Keyboard::inlineButton([
                            'text' => ($checked ? "\u{2705} " : "\u{2B1C} ").$label,
                            'callback_data' => 'set:sev:'.$media['id'].':'.$bit,
                        ]);
                    }
                    $keyboard->row($row);
                }
            }
        }
        // "Закрити" й тут - щоб не повертатися на головну лише заради закриття панелі
        $keyboard->row([
            Keyboard::inlineButton(['text' => $i18n['back'], 'callback_data' => 'set:main']),
            Keyboard::inlineButton(['text' => $i18n['close'], 'callback_data' => 'set:close']),
        ]);

        return [$text, $keyboard];
    }
}
