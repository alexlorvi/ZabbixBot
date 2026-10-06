<?php
declare(strict_types=1);

namespace ZbxBot\Telegram;

interface Transport
{
    /**
     * @param array<string,mixed> $params
     * @throws TelegramException
     */
    public function request(string $method, array $params): void;
}
