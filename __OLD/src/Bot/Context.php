<?php
declare(strict_types=1);

namespace ZbxBot\Bot;

use Psr\Log\LoggerInterface;
use ZbxBot\Telegram\Messenger;

final class Context
{
    /**
     * @param array<int|string,string> $matches
     * @param array<string,string>|null $user дані користувача Zabbix
     */
    public function __construct(
        public readonly string $chatId,
        public readonly string $username,
        public readonly string $text,
        public array $matches,
        public readonly ?array $user,
        public readonly bool $isAdmin,
        public readonly LoggerInterface $log,
        private readonly Messenger $messenger,
    ) {
    }

    public function reply(string $text, ?string $parseMode = null, ?array $markup = null): void
    {
        $this->messenger->send($this->chatId, $text, $parseMode, $markup);
    }

    public function replyBlocks(array $blocks, string $sep = "\n"): void
    {
        $this->messenger->sendBlocks($this->chatId, $blocks, $sep);
    }
}
