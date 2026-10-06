<?php
declare(strict_types=1);

namespace ZbxBot\Telegram;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Messenger
{
    public const LIMIT = 4096;

    public function __construct(private readonly Transport $transport, private LoggerInterface $log = new NullLogger())
    {
    }

    public function withLogger(LoggerInterface $log): self
    {
        $clone = clone $this;
        $clone->log = $log;
        return $clone;
    }

    /**
     * @param array<string,mixed>|null $markup повний reply_markup; null - прибрати клавіатуру
     */
    public function send(string $chatId, string $text, ?string $parseMode = null, ?array $markup = null): void
    {
        if (trim($text) === '') {
            $this->log->error('Empty message skipped');
            return;
        }
        $this->log->info('<  '.$text);
        $isHtml = strtolower((string)$parseMode) === 'html';
        foreach (self::chunk($text, $isHtml) as $part) {
            $this->push($chatId, $part, $parseMode, $markup);
        }
    }

    /** Прибирає «годинник» на натиснутій inline-кнопці. */
    public function answerCallback(string $callbackId): void
    {
        try {
            $this->transport->request('answerCallbackQuery', ['callback_query_id' => $callbackId]);
        } catch (TelegramException $e) {
            $this->log->error('Telegram: '.$e->getMessage());
        }
    }

    /** Склеює блоки в повідомлення до 4096 символів (замість одного повідомлення на блок). */
    public function sendBlocks(string $chatId, array $blocks, string $sep = "\n"): void
    {
        foreach (self::packBlocks($blocks, $sep) as $text) {
            $this->send($chatId, $text);
        }
    }

    /**
     * Ділить текст по рядках. Для HTML не рве <pre>: закриває його в кінці частини і відкриває в наступній.
     * @return list<string>
     */
    public static function chunk(string $text, bool $html = false, int $limit = self::LIMIT): array
    {
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
    public static function packBlocks(array $blocks, string $sep = "\n", int $limit = self::LIMIT): array
    {
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

    private function push(string $chatId, string $text, ?string $parseMode, ?array $markup): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($markup ?? ['remove_keyboard' => true], JSON_UNESCAPED_UNICODE),
        ];
        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }
        try {
            $this->transport->request('sendMessage', $params);
        } catch (TelegramException $e) {
            $this->log->error('Telegram: '.$e->getMessage());
        }
    }
}
