<?php

namespace ZabbixBot\Services;

/** Чиста класифікація тексту/callback_data авторизованого користувача (без побічних ефектів, тестується). */
final class Router
{
    /**
     * @param array<string,mixed> $menuActions ключі reply-клавіатури /menu
     * @return array{0:string,1:string} [тип, аргумент]; типи: ev, hostid, menu, set, net, ack, ackmsg, sec, hours, menuaction, text, command
     */
    public static function classify(string $text, array $menuActions = []): array
    {
        if (preg_match('/^\/ev([0-9]+)$/i', $text, $m)) return ['ev', $m[1]];
        if (preg_match('/^\/hostid([0-9]+)$/i', $text, $m)) return ['hostid', $m[1]];
        if (str_starts_with($text, 'menu:')) return ['menu', substr($text, 5)];
        if (str_starts_with($text, 'set:')) return ['set', substr($text, 4)];
        if (str_starts_with($text, 'net:')) return ['net', substr($text, 4)];
        if (preg_match('/^ack:([0-9]+)$/', $text, $m)) return ['ack', $m[1]];
        if (preg_match('/^ackmsg:([0-9]+)$/', $text, $m)) return ['ackmsg', $m[1]];
        if (preg_match('/^\/([0-9]+)sec$/i', $text, $m)) return ['sec', $m[1]];
        if (preg_match('/^\/([0-9]+)h$/i', $text, $m)) return ['hours', $m[1]];
        if (array_key_exists($text, $menuActions)) return ['menuaction', $text];
        if (!str_starts_with($text, '/')) return ['text', $text];
        return ['command', $text];
    }

    /**
     * callback_data inline-кнопки. Відрізняється від classify() тим, що "/команда" не можна віддати в
     * commandsHandler SDK: для callback_query він розбирає текст повідомлення з кнопкою, а не callback_data.
     * Старі кнопки картки хоста ("/ping <ip>", "/cisco <ip>", "/apc <ip>") перекладаються в net:<tool>:<ip>.
     * @return array{0:string,1:string} як у classify(), плюс тип "ignored"
     */
    public static function classifyCallback(string $data): array
    {
        if (preg_match('/^\/(ping|cisco|apc) (\S+)$/', $data, $m)) return ['net', $m[1].':'.$m[2]];
        $route = self::classify($data);
        return in_array($route[0], ['command', 'text'], true) ? ['ignored', $data] : $route;
    }

    /**
     * Аргумент net:-callback ("ping.200:10.0.0.1", "cisco:10.0.0.1", старе "ping:10.0.0.1").
     * Кількість пакетів - у назві інструмента, бо адреса (IPv6) сама містить ":".
     * @return array{tool:string,target:string,count:?int}
     */
    public static function parseNet(string $arg): array
    {
        [$tool, $target] = array_pad(explode(':', $arg, 2), 2, '');
        $count = null;
        if (preg_match('/^([a-z]+)\.([0-9]+)$/', $tool, $m)) {
            [$tool, $count] = [$m[1], (int)$m[2]];
        }
        return ['tool' => $tool, 'target' => $target, 'count' => $count];
    }

    /**
     * Inline-кнопка "Повторити" для результату мережевої команди (JSON reply_markup).
     * null - callback_data задовга (Telegram: до 64 байт), тоді без кнопки.
     */
    public static function repeatMarkup(string $tool, string $target, ?int $count, string $label): ?string
    {
        $data = 'net:'.$tool.($count !== null ? '.'.$count : '').':'.$target;
        if (strlen($data) > 64) {
            return null;
        }
        return json_encode(['inline_keyboard' => [[['text' => $label, 'callback_data' => $data]]]], JSON_UNESCAPED_UNICODE);
    }

    /** Перевірка секрету вебхука: порожній секрет у конфізі = перевірка вимкнена. */
    public static function secretValid(string $configured, string $header): bool
    {
        return $configured === '' || hash_equals($configured, $header);
    }
}
