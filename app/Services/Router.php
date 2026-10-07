<?php

namespace ZabbixBot\Services;

/** Чиста класифікація тексту/callback_data авторизованого користувача (без побічних ефектів, тестується). */
final class Router
{
    /**
     * @param array<string,mixed> $menuActions ключі reply-клавіатури /menu
     * @return array{0:string,1:string} [тип, аргумент]; типи: ev, hostid, menu, set, sec, hours, menuaction, text, command
     */
    public static function classify(string $text, array $menuActions = []): array
    {
        if (preg_match('/^\/ev([0-9]+)$/i', $text, $m)) return ['ev', $m[1]];
        if (preg_match('/^\/hostid([0-9]+)$/i', $text, $m)) return ['hostid', $m[1]];
        if (str_starts_with($text, 'menu:')) return ['menu', substr($text, 5)];
        if (str_starts_with($text, 'set:')) return ['set', substr($text, 4)];
        if (preg_match('/^\/([0-9]+)sec$/i', $text, $m)) return ['sec', $m[1]];
        if (preg_match('/^\/([0-9]+)h$/i', $text, $m)) return ['hours', $m[1]];
        if (array_key_exists($text, $menuActions)) return ['menuaction', $text];
        if (!str_starts_with($text, '/')) return ['text', $text];
        return ['command', $text];
    }

    /** Перевірка секрету вебхука: порожній секрет у конфізі = перевірка вимкнена. */
    public static function secretValid(string $configured, string $header): bool
    {
        return $configured === '' || hash_equals($configured, $header);
    }
}
