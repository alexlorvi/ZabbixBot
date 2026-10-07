<?php

namespace ZabbixBot\Services;

/** Чисте формування списку команд для /help: адмінські команди бачать лише адміни. */
final class CommandList
{
    /**
     * @param array<string,string> $descriptions ім'я команди => опис
     * @param list<string> $adminOnly імена команд, видимих лише адмінам
     */
    public static function render(array $descriptions, array $adminOnly, bool $isAdmin): string
    {
        $out = '';
        foreach ($descriptions as $name => $description) {
            $isAdminCommand = in_array($name, $adminOnly, true);
            if ($isAdminCommand && !$isAdmin) {
                continue;
            }
            $out .= sprintf('/%s - %s%s', $name, $description, PHP_EOL);
        }
        return $out;
    }
}
