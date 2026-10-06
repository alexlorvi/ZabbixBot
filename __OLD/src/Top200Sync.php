<?php
declare(strict_types=1);

namespace ZbxBot;

final class Top200Sync
{
    /** Хост, який завжди входить у TOP200 */
    public const EXTRA_HOSTID = '13747';

    /**
     * Різниця між потрібним складом TOP200 і поточним.
     * Хости TOP200, яких немає в WogRouters (додані вручну), не чіпаємо.
     * @param list<array<string,mixed>> $routers хости групи WogRouters (з inventory.tag)
     * @param list<string> $currentTop hostid, що зараз у TOP200
     * @return array{add:list<string>,remove:list<string>,wanted:int}
     */
    public static function plan(array $routers, array $currentTop): array
    {
        $all = [];
        $wanted = [];
        foreach ($routers as $host) {
            $tag = (int)($host['inventory']['tag'] ?? 0);
            $id = (string)$host['hostid'];
            $all[] = $id;
            if (($tag > 0 && $tag < 201) || $id === self::EXTRA_HOSTID) {
                $wanted[] = $id;
            }
        }
        $currentTop = array_map('strval', $currentTop);
        return [
            'add' => array_values(array_diff($wanted, $currentTop)),
            'remove' => array_values(array_diff(array_intersect($currentTop, $all), $wanted)),
            'wanted' => count($wanted),
        ];
    }
}
