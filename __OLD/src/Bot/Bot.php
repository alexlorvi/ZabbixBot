<?php
declare(strict_types=1);

namespace ZbxBot\Bot;

use Psr\Log\LoggerInterface;
use ZbxBot\Cache\FileCache;
use ZbxBot\Cache\RateLimiter;
use ZbxBot\Cache\UpdateDeduplicator;
use ZbxBot\Log\LoggerFactory;
use ZbxBot\Telegram\Messenger;
use ZbxBot\Zabbix\ZabbixService;

/** Обробка одного update: секрет вебхука -> дедуплікація -> доступ -> ліміти -> маршрут. */
final class Bot
{
    public function __construct(
        private readonly array $config,
        private readonly Router $router,
        private readonly Commands $commands,
        private readonly ZabbixService $zbx,
        private readonly Messenger $messenger,
        private readonly LoggerFactory $logs,
        private readonly RateLimiter $limiter,
        private readonly UpdateDeduplicator $dedupe,
        private readonly FileCache $cache,
    ) {
    }

    /**
     * @param array<string,mixed> $server $_SERVER
     * @param callable|null $ack викликається, коли update прийнято: звідси можна віддати 200 і далі працювати у фоні
     * @return int HTTP-код
     */
    public function handle(string $body, array $server, ?callable $ack = null): int
    {
        $main = $this->logs->main();

        $secret = (string)($this->config['webhook_secret'] ?? '');
        if ($secret !== '' && !hash_equals($secret, (string)($server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
            $main->warning('Webhook secret mismatch from '.($server['REMOTE_ADDR'] ?? '?'));
            return 403;
        }

        $update = json_decode($body, true);
        // Натискання inline-кнопки обробляємо як текстову команду з callback_data
        $callback = $update['callback_query'] ?? null;
        if (is_array($callback) && isset($callback['data'], $callback['id'], $callback['message']['chat']['id'])) {
            $text = (string)$callback['data'];
            $chatId = $callback['message']['chat']['id'];
            $username = (string)($callback['from']['username'] ?? '');
        } else {
            $callback = null;
            $text = $update['message']['text'] ?? null;
            $chatId = $update['message']['chat']['id'] ?? null;
            $username = (string)($update['message']['chat']['username'] ?? '');
        }
        if (!is_string($text) || $chatId === null || !isset($update['update_id'])) {
            $main->info('Skip update without text: '.mb_substr($body, 0, 300));
            return 200;
        }
        $chatId = (string)$chatId;

        if ($this->dedupe->seen((int)$update['update_id'])) {
            $main->info('Duplicate update '.$update['update_id']);
            return 200;
        }

        if ($callback !== null) {
            $this->messenger->answerCallback((string)$callback['id']);
        }
        if ($ack !== null) {
            $ack();
        }
        if (random_int(1, 200) === 1) {
            $this->cache->gc(7 * 86400);
        }

        $log = $this->logs->chat($chatId);
        $log->info('>  '.$text, [$username]);

        try {
            $this->dispatch($chatId, $username, $text, $log);
        } catch (\Throwable $e) {
            $log->error(get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            $main->error(get_class($e).': '.$e->getMessage());
            $this->messenger->withLogger($log)->send($chatId, 'Внутрішня помилка. Спробуйте пізніше.');
        }
        return 200;
    }

    private function dispatch(string $chatId, string $username, string $text, LoggerInterface $log): void
    {
        $messenger = $this->messenger->withLogger($log);
        $user = $this->zbx->findUser($chatId);
        $isAdmin = in_array($chatId, array_map('strval', $this->config['admins'] ?? []), true);

        // у групах Telegram додає @botname до команди
        $text = (string)preg_replace('~^(/\w+)@\w+~u', '$1', trim($text));

        $route = $this->router->match($text);
        [$handler, $access, $matches] = $route ?? [[$this->commands, 'fallback'], Access::User, []];

        if ($access !== Access::Anyone && $user === null) {
            // стороннім - одна відмова за 10 хвилин, далі мовчки (не навантажуємо Telegram/логи)
            if ($this->limiter->allow('stranger:'.$chatId, 1, 600)) {
                $log->error('Not Zabbix User. Reject');
                $messenger->send($chatId, 'Я не працюю з тими, кого не знаю.');
            }
            return;
        }
        if ($access === Access::Admin && !$isAdmin) {
            $messenger->send($chatId, 'Га?');
            return;
        }

        [$max, $window] = $this->config['rate_limit'] ?? [30, 60];
        if (!$this->limiter->allow('cmd:'.$chatId, (int)$max, (int)$window)) {
            $log->warning('Rate limit exceeded');
            if ($this->limiter->allow('cmd-notice:'.$chatId, 1, 60)) {
                $messenger->send($chatId, 'Забагато запитів. Зачекайте хвилину.');
            }
            return;
        }

        $ctx = new Context($chatId, $username, $text, $matches, $user, $isAdmin, $log, $messenger);
        $handler($ctx);
    }
}
