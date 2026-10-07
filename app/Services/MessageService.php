<?php

namespace ZabbixBot\Services;

use Telegram\Bot\Api;
use Telegram\Bot\Actions;
use Telegram\Bot\Keyboard\Keyboard;
use ZabbixBot\Services\MessageQueue;

class MessageService {

    public const LIMIT = 4096;

    protected Api $telegram;
    protected MessageQueue $messageQueue;

    public function __construct(Api $tgApi) {
        $this->telegram = $tgApi;
        $this->messageQueue = new MessageQueue();
    }

    public function chatActionTyping( $chatID) {
        $this->telegram->sendChatAction(['chat_id'=>$chatID,'action' => Actions::TYPING]);
    }

    public function sendMessage($chatId,string $message,$options = []) {
        if (trim($message) === '') {
            userLOG($chatId,'error','Empty message skipped');
            return;
        }
        $this->chatActionTyping($chatId);
        $isHtml = strtolower((string)($options['parse_mode'] ?? '')) === 'html';
        foreach (self::chunk($message, $isHtml) as $messageline) {
            $sendArray = $this->prepareParams(array_merge([
                'chat_id' => $chatId,
                'text' => $messageline,
            ],$options));
            try {
                $this->telegram->sendMessage($sendArray);
                userLOG($chatId,'info','< '.$messageline);
            } catch (\Exception $e) {
                // If there's an error, enqueue the message
                userLOG($chatId,'error','Send Error - '.$e->getMessage().PHP_EOL.'Enqueue it.');
                $this->messageQueue->enqueue($sendArray);
            }
        }
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

    // CopyPaste from Copilot. Edit before use
    public function retryMessages() {
        while ($this->messageQueue->getQueueSize() > 0) {
            $message = $this->messageQueue->dequeue();
            try {
                $this->sendMessage($message['chat_id'], $message['text']);
            } catch (\Exception $e) {
                // Re-enqueue the message if it fails again
                $this->messageQueue->enqueue($message);
                break;
                // Stop retrying if the proxy is still down
            }
        }
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