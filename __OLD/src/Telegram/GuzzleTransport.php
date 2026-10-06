<?php
declare(strict_types=1);

namespace ZbxBot\Telegram;

use GuzzleHttp\Client;

/** Bot API через Guzzle 7. Повторює запит при 429, поважаючи retry_after. */
final class GuzzleTransport implements Transport
{
    private const MAX_RETRIES = 2;
    private const MAX_WAIT = 10;

    private Client $http;

    public function __construct(string $botToken, ?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://api.telegram.org/bot'.$botToken.'/',
            'timeout' => 15,
            'http_errors' => false,
        ]);
    }

    public function request(string $method, array $params): void
    {
        for ($attempt = 0;; $attempt++) {
            try {
                $resp = $this->http->post($method, ['form_params' => $params]);
            } catch (\Throwable $e) {
                // Текст винятку Guzzle містить URL з токеном бота - не віддаємо його далі
                throw new TelegramException("$method: ".get_class($e));
            }
            $body = json_decode((string)$resp->getBody(), true);
            if (is_array($body) && ($body['ok'] ?? false)) {
                return;
            }
            $code = (int)($body['error_code'] ?? $resp->getStatusCode());
            if ($code === 429 && $attempt < self::MAX_RETRIES) {
                sleep(min(self::MAX_WAIT, max(1, (int)($body['parameters']['retry_after'] ?? 1))));
                continue;
            }
            throw new TelegramException("$method: ".$code.' '.(string)($body['description'] ?? 'unknown error'));
        }
    }
}
