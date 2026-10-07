<?php

namespace ZabbixBot\Services;

/**
 * Приймає сповіщення з Zabbix (поля як у вбудованих webhook-медіатипах) і шле їх у Telegram.
 * - проблема: надсилає і запам'ятовує message_id (AlertStore);
 * - оновлення проблеми (коментар/квитування): відповідь на сповіщення про проблему, запис лишається;
 * - відновлення: відповідь на сповіщення про проблему + запис видаляється; якщо запису немає - звичайне надсилання.
 *
 * Транспорт і перевірка користувача передаються closure-ами, тому клас тестується без мережі.
 */
class AlertService
{
    /**
     * @param callable(string,string,array):?int $send ($chatId, $text, $options) => message_id|null
     * @param callable(string):bool $isKnownUser чи відомий chat id як користувач Zabbix
     */
    public function __construct(
        private readonly AlertStore $store,
        private $send,
        private $isKnownUser,
        private readonly bool $requireKnownUser = true,
    ) {
    }

    /**
     * @param array<string,mixed> $p sendto, subject, message, event_id, event_value (1 проблема / 0 відновлення),
     *                               event_update_status (1 = оновлення), parse_mode
     * @return array{ok:bool,status:int,error?:string,message_id?:int|null,mode?:string}
     */
    public function handle(array $p): array
    {
        $chatId = trim((string)($p['sendto'] ?? ''));
        if (!preg_match('/^-?[0-9]+$/', $chatId)) {
            return ['ok' => false, 'status' => 400, 'error' => 'sendto must be a numeric chat id'];
        }
        $subject = trim((string)($p['subject'] ?? ''));
        $body = trim((string)($p['message'] ?? ''));
        $text = $subject !== '' && $body !== '' ? $subject."\n".$body : $subject.$body;
        if ($text === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'empty subject and message'];
        }
        if ($this->requireKnownUser && !($this->isKnownUser)($chatId)) {
            return ['ok' => false, 'status' => 403, 'error' => 'unknown recipient'];
        }

        $eventId = preg_replace('/[^0-9]/', '', (string)($p['event_id'] ?? ''));
        $isRecovery = (string)($p['event_value'] ?? '1') === '0';
        $isUpdate = (string)($p['event_update_status'] ?? '0') === '1';
        $mode = $isUpdate ? 'update' : ($isRecovery ? 'recovery' : 'problem');

        $options = ['keep_keyboard' => true];
        $parseMode = strtolower((string)($p['parse_mode'] ?? ''));
        if (in_array($parseMode, ['html', 'markdown', 'markdownv2'], true)) {
            $options['parse_mode'] = $parseMode;
        }

        $replyTo = null;
        if ($eventId !== '' && $mode !== 'problem') {
            $replyTo = $this->store->get($eventId, $chatId);
            if ($replyTo !== null) {
                $options['reply_parameters'] = ['message_id' => $replyTo, 'allow_sending_without_reply' => true];
            }
        }

        $messageId = ($this->send)($chatId, $text, $options);

        if ($eventId !== '') {
            if ($mode === 'problem' && $messageId !== null) {
                $this->store->put($eventId, $chatId, $messageId);
            } elseif ($mode === 'recovery') {
                $this->store->delete($eventId, $chatId);
            }
        }
        $this->store->purgeExpired();

        return ['ok' => true, 'status' => 200, 'message_id' => $messageId, 'mode' => $mode.($replyTo !== null ? '+reply' : '')];
    }
}
