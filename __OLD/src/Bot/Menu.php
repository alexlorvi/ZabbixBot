<?php
declare(strict_types=1);

namespace ZbxBot\Bot;

final class Menu
{
    public const BTN_DETAIL = "\u{1F4D6} Деталізація активних";
    public const BTN_LIST = "\u{1F4CB} Список активних";
    public const BTN_HELP = "\u{2754} Довідка";

    /** @return array<string,mixed> */
    public static function markup(): array
    {
        return [
            'keyboard' => [[self::BTN_DETAIL], [self::BTN_LIST], [self::BTN_HELP]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    public static function help(bool $admin): string
    {
        $text = "<b>Додатково доступні команди:</b>\n".
            "/host <b>ip або частина імені</b> - пошук хоста, стан і активні проблеми (якщо збігів багато - кнопки)\n".
            "/ping <b>ip</b> - пінг хоста, або декількох (до 5), розділених пробілом\n".
            "/ping1..50 - варіації кількості пакетів пінгування\n".
            "/cisco <b>ip</b> - статус Cisco. Останній октет примусово міняється на 1\n".
            "/apc &lt;ip&gt; - комплексне тестування карти керування APC\n".
            "/top200list - Список проблем по ТОП200\n".
            "/top200full - Перелік проблем по ТОП200\n".
            "/gold_list - Список проблем по Золотим АЗК\n".
            "/gold_full - Перелік проблем по Золотим АЗК\n".
            "/oilbases_list - Список проблем по Нафтобазам\n".
            "/oilbases_full - Перелік проблем по Нафтобазам\n".
            "/72h - інциденти відкриті більше 72 годин\n".
            "/24h - інциденти відкриті більше 24 годин";
        if ($admin) {
            $text = "<b>Адміністратору додатково:</b>\n".
                "/reset - скинути кеш користувачів і груп Zabbix\n\n".$text;
        }
        return $text;
    }
}
