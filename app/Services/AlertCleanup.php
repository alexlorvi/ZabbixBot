<?php

namespace ZabbixBot\Services;

/**
 * Автовидалення сповіщень (app:alert-cleanup у cron): для подій, відновлених щонайменше $hours годин тому,
 * видаляє з чату всі сповіщення події (проблема, оновлення, відновлення) і забуває запис.
 * Telegram дозволяє боту видаляти лише повідомлення, молодші за 48 год, - старіші пропускаються (лишаються в чаті).
 * Якщо користувач вимкнув автовидалення (/settings), запис просто забувається, повідомлення не чіпаються.
 *
 * Транспорт і налаштування користувача - closure-ами, тому тестується без мережі.
 */
final class AlertCleanup
{
    /** Межа Telegram на видалення повідомлень ботом. */
    public const DELETE_WINDOW = 48 * 3600;
    /** Запас до межі: за цей час повідомлення може "постаріти", поки до нього дійде черга. */
    private const MARGIN = 600;
    public const MIN_HOURS = 1;
    public const MAX_HOURS = 47;

    /**
     * @param callable(string):bool $enabled чи ввімкнене автовидалення в чаті
     * @param callable(string,int):bool $delete видалити повідомлення в чаті
     * @return array{events:int,deleted:int,failed:int,tooOld:int,skipped:int} skipped - подій з вимкненим автовидаленням
     */
    public static function run(AlertStore $store, int $now, int $hours, callable $enabled, callable $delete): array
    {
        $hours = max(self::MIN_HOURS, min(self::MAX_HOURS, $hours));
        $stats = ['events' => 0, 'deleted' => 0, 'failed' => 0, 'tooOld' => 0, 'skipped' => 0];
        $enabledCache = [];
        foreach ($store->recoveredBefore($now - $hours * 3600) as $rec) {
            $chatId = $rec['chat_id'];
            $stats['events']++;
            if (!($enabledCache[$chatId] ??= (bool)$enabled($chatId))) {
                $stats['skipped']++;
            } else {
                foreach ($rec['messages'] as [$messageId, $sentAt]) {
                    if ($now - (int)$sentAt > self::DELETE_WINDOW - self::MARGIN) {
                        $stats['tooOld']++;
                        continue;
                    }
                    if ($delete($chatId, (int)$messageId)) {
                        $stats['deleted']++;
                        $store->forgetMessage($chatId, (int)$messageId);
                    } else {
                        $stats['failed']++; // уже видалене користувачем, чат заблоковано... - повторювати немає сенсу
                    }
                }
            }
            $store->delete($rec['event_id'], $chatId);
        }
        return $stats;
    }
}
