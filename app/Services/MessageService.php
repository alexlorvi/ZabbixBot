<?php

namespace ZabbixBot\Services;

use Telegram\Bot\Api;
use Telegram\Bot\Actions;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Exceptions\TelegramResponseException;

class MessageService {

    public const LIMIT = 4096;

    protected Api $telegram;
    protected MessageQueue $messageQueue;
    private ?AlertStore $alerts;

    public function __construct(Api $tgApi, ?AlertStore $alerts = null) {
        $this->telegram = $tgApi;
        $this->messageQueue = new MessageQueue();
        $this->alerts = $alerts;
    }

    private function alerts(): AlertStore {
        return $this->alerts ??= new AlertStore(ALERT_PATH, (int)ConfigService::getInstance()->getNested('alerts.ttl_days', 30));
    }

    public function chatActionTyping( $chatID) {
        $this->telegram->sendChatAction(['chat_id'=>$chatID,'action' => Actions::TYPING]);
    }

    /**
     * Надсилає повідомлення (з розбиттям на частини). Повертає message_id першої доставленої частини
     * або null, якщо нічого не доставлено (тоді повідомлення вже у черзі повторів).
     * Опції: keep_keyboard=true - не знімати reply-клавіатуру користувача (для сповіщень);
     * alert={event_id,chat_id,mode} - сповіщення Zabbix: якщо воно потрапить у чергу, після доставки
     * через app:retry-messages оновиться AlertStore, а для тієї ж події в черзі порядок зберігається.
     */
    public function sendMessage($chatId,string $message,$options = []): ?int {
        if (trim($message) === '') {
            userLOG($chatId,'error','Empty message skipped');
            return null;
        }
        $keepKeyboard = !empty($options['keep_keyboard']);
        $alert = $options['alert'] ?? null;
        unset($options['keep_keyboard'], $options['alert']);
        $this->chatActionTyping($chatId);
        $isHtml = strtolower((string)($options['parse_mode'] ?? '')) === 'html';
        $mustQueue = $alert !== null && $this->messageQueue->hasAlert((string)$alert['event_id'], (string)$alert['chat_id']);
        $firstId = null;
        $first = true;
        foreach (self::chunk($message, $isHtml) as $messageline) {
            $sendArray = array_merge([
                'chat_id' => $chatId,
                'text' => $messageline,
            ],$options);
            if (!$keepKeyboard) {
                $sendArray = $this->prepareParams($sendArray);
            }
            if (isset($sendArray['reply_markup']) && !is_string($sendArray['reply_markup'])) {
                $sendArray['reply_markup'] = (string)$sendArray['reply_markup']; // у черзі лежить JSON-рядок
            }
            $queueItem = ($first && $alert !== null) ? $sendArray + ['_alert' => $alert] : $sendArray;
            $first = false;
            if ($mustQueue) {
                $this->messageQueue->enqueue($queueItem);
                continue;
            }
            try {
                $sent = $this->telegram->sendMessage($sendArray);
                $sentArr = $sent->toArray();
                $firstId ??= (int)($sentArr['message_id'] ?? $sentArr['result']['message_id'] ?? 0);
                userLOG($chatId,'info','< '.$messageline);
            } catch (\Exception $e) {
                // If there's an error, enqueue the message
                userLOG($chatId,'error','Send Error - '.$e->getMessage().PHP_EOL.'Enqueue it.');
                $this->messageQueue->enqueue($queueItem);
                $mustQueue = true; // наступні частини - слідом, щоб не порушити порядок
            }
        }
        return $firstId ?: null;
    }

    /** Редагує вже надіслане повідомлення (для панелей на callback_query, щоб не плодити нові повідомлення). */
    public function editMessage($chatId, $messageId, string $text, $markup = null, $options = []) {
        if (trim($text) === '') {
            userLOG($chatId,'error','Empty edit text skipped');
            return;
        }
        $isHtml = strtolower((string)($options['parse_mode'] ?? '')) === 'html';
        $parts = self::chunk($text, $isHtml);
        $editArray = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $parts[0],
        ], $options);
        if ($markup !== null) {
            $editArray['reply_markup'] = $markup;
        }
        try {
            $this->telegram->editMessageText($editArray);
            userLOG($chatId,'info','~ '.$parts[0]);
        } catch (\Exception $e) {
            userLOG($chatId,'error','Edit Error - '.$e->getMessage());
        }
    }

    /** Видаляє повідомлення (кнопка "Закрити" в панелях). */
    public function deleteMessage($chatId, $messageId): void {
        try {
            $this->telegram->deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (\Exception $e) {
            userLOG($chatId,'error','Delete Error - '.$e->getMessage());
        }
    }

    /** Склеює блоки (напр. одна подія = один блок) у повідомлення до LIMIT символів замість одного повідомлення на блок. */
    public function sendBlocks($chatId, array $blocks, string $sep = "\n", $options = []) {
        foreach (self::packBlocks($blocks, $sep) as $text) {
            $this->sendMessage($chatId, $text, $options);
        }
    }

    /**
     * Ділить текст по рядках. Для HTML не рве <pre>: закриває його в кінці частини і відкриває в наступній.
     * @return list<string>
     */
    public static function chunk(string $text, bool $html = false, int $limit = self::LIMIT): array {
        $max = $html ? $limit - 12 : $limit; // запас на "</pre>" і "<pre>"
        $parts = [];
        $cur = '';
        foreach (explode("\n", $text) as $line) {
            foreach (mb_strlen($line) > $max ? mb_str_split($line, $max) : [$line] as $piece) {
                if ($cur === '') {
                    $cur = $piece;
                } elseif (mb_strlen($cur) + 1 + mb_strlen($piece) > $max) {
                    $parts[] = $cur;
                    $cur = $piece;
                } else {
                    $cur .= "\n".$piece;
                }
            }
        }
        if ($cur !== '') {
            $parts[] = $cur;
        }
        if (!$html) {
            return $parts;
        }
        $carry = false;
        foreach ($parts as $i => $part) {
            if ($carry) {
                $part = '<pre>'.$part;
            }
            $carry = substr_count($part, '<pre') > substr_count($part, '</pre>');
            $parts[$i] = $carry ? $part.'</pre>' : $part;
        }
        return $parts;
    }

    /** @return list<string> */
    public static function packBlocks(array $blocks, string $sep = "\n", int $limit = self::LIMIT): array {
        $out = [];
        $cur = '';
        foreach ($blocks as $block) {
            $block = (string)$block;
            if (mb_strlen($block) > $limit) {
                if ($cur !== '') {
                    $out[] = $cur;
                    $cur = '';
                }
                array_push($out, ...self::chunk($block, false, $limit));
            } elseif ($cur === '') {
                $cur = $block;
            } elseif (mb_strlen($cur) + mb_strlen($sep) + mb_strlen($block) > $limit) {
                $out[] = $cur;
                $cur = $block;
            } else {
                $cur .= $sep.$block;
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }
        return $out;
    }

    /**
     * Висилає повідомлення з черги по порядку (не більше $limit). Зупиняється на першій тимчасовій помилці
     * (Telegram/проксі ще недоступні), нічого не втрачаючи. Назавжди відхилені (HTTP 400/403, напр. бот заблокований)
     * відкидаються, щоб не заблокувати чергу. Для сповіщень Zabbix після доставки оновлює AlertStore
     * (проблема - запам'ятати message_id, відновлення - забути запис; відповідь на проблему визначається саме тут).
     * @return int скільки доставлено
     */
    public function retryMessages(int $limit = PHP_INT_MAX): int {
        $delivered = 0;
        while ($delivered < $limit && ($item = $this->messageQueue->peek()) !== null) {
            $alert = $item['_alert'] ?? null;
            unset($item['_alert']);
            if (isset($item['reply_markup']) && !is_string($item['reply_markup'])) {
                $item['reply_markup'] = json_encode($item['reply_markup']);
            }
            if ($alert !== null && $alert['mode'] !== 'problem' && !isset($item['reply_parameters'])) {
                $replyTo = $this->alerts()->get((string)$alert['event_id'], (string)$alert['chat_id']);
                if ($replyTo !== null) {
                    $item['reply_parameters'] = json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true]);
                }
            }
            try {
                $sent = $this->telegram->sendMessage($item)->toArray();
                $messageId = (int)($sent['message_id'] ?? $sent['result']['message_id'] ?? 0);
            } catch (TelegramResponseException $e) {
                if (!in_array($e->getHttpStatusCode(), [400, 403], true)) {
                    userLOG($item['chat_id'],'error','Retry stopped - '.$e->getMessage());
                    break;
                }
                userLOG($item['chat_id'],'error','Dropped undeliverable queued message - '.$e->getMessage());
                $messageId = 0;
            } catch (\Exception $e) {
                userLOG($item['chat_id'],'error','Retry stopped - '.$e->getMessage());
                break;
            }
            $this->messageQueue->dequeue();
            $delivered++;
            if ($alert !== null) {
                if ($alert['mode'] === 'problem' && $messageId > 0) {
                    $this->alerts()->put((string)$alert['event_id'], (string)$alert['chat_id'], $messageId);
                } elseif ($alert['mode'] === 'recovery') {
                    $this->alerts()->delete((string)$alert['event_id'], (string)$alert['chat_id']);
                }
            }
        }
        return $delivered;
    }

    public function getMessageQueueSize() {
        return $this->messageQueue->getQueueSize();
    }
    
    private function prepareParams(array $params):array {
        $reply_markup = Keyboard::remove(['selective' => false]);
        $defaultParams = [
            'reply_markup' => $reply_markup,
        ];
        $compare = array_diff($defaultParams,$params);
        return array_merge($compare,$params);
    }

}