<?php

namespace ZabbixBot\Services;

/**
 * Квитування і коментарі проблем Zabbix з Telegram.
 * - кнопки "Квитувати"/"Коментар" під сповіщенням про проблему і під /ev<id> (callback ack:<id> / ackmsg:<id>);
 * - відповідь (reply) текстом на повідомлення бота про подію = коментар у Zabbix.
 * Подія визначається за message_id (зворотний індекс AlertStore), інакше за єдиним "/ev<id>" у тексті повідомлення.
 *
 * Сам виклик Zabbix передається closure-ою (токен користувача вже всередині), тому клас тестується без мережі.
 */
class AckService
{
    /** Обмеження Zabbix на довжину повідомлення квитування. */
    public const MAX_MESSAGE = 2048;

    /**
     * @param callable(string,int,?string):?string $acknowledge ($eventId, $action, $message) => null|текст помилки
     */
    public function __construct(
        private $acknowledge,
    ) {
    }

    /** Подія, до якої належить повідомлення бота, на яке відповів користувач. */
    public static function eventIdFor(AlertStore $store, string $chatId, int $messageId, string $messageText): ?string
    {
        return $store->eventForMessage($chatId, $messageId) ?? self::eventIdFromText($messageText);
    }

    /** Єдиний "/ev<id>" у тексті; у переліку з кількох подій не вгадуємо - null. */
    public static function eventIdFromText(string $text): ?string
    {
        preg_match_all('/\/ev([0-9]+)\b/', $text, $m);
        $ids = array_values(array_unique($m[1]));
        return count($ids) === 1 ? $ids[0] : null;
    }

    /** @return string|null null - успіх, інакше текст помилки */
    public function acknowledge(string $eventId): ?string
    {
        if (!ctype_digit($eventId)) {
            return 'invalid event id';
        }
        return ($this->acknowledge)($eventId, ZabbixService::ACK_ACKNOWLEDGE, null);
    }

    /** @return string|null null - успіх, інакше текст помилки */
    public function comment(string $eventId, string $text): ?string
    {
        $text = trim($text);
        if (!ctype_digit($eventId)) {
            return 'invalid event id';
        }
        if ($text === '') {
            return 'empty comment';
        }
        return ($this->acknowledge)($eventId, ZabbixService::ACK_MESSAGE, mb_substr($text, 0, self::MAX_MESSAGE));
    }

    /**
     * Inline-клавіатура (JSON-рядок, як очікує Telegram і черга повторів).
     * @param array{ack:string,comment:string} $labels
     */
    public static function keyboard(string $eventId, array $labels, bool $withAck = true): string
    {
        $row = [];
        if ($withAck) {
            $row[] = ['text' => $labels['ack'], 'callback_data' => 'ack:'.$eventId];
        }
        $row[] = ['text' => $labels['comment'], 'callback_data' => 'ackmsg:'.$eventId];
        return json_encode(['inline_keyboard' => [$row]], JSON_UNESCAPED_UNICODE);
    }
}
