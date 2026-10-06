# zbx-bot-prod

Telegram-бот (PHP, webhook) для моніторингу Zabbix у WOG: списки/деталі активних проблем severity Disaster,
`/ping`, `/cisco`, `/apc`. Авторизація — користувач має бути в Zabbix з медіа Telegram (`sendto` = chat id).

## Правила для Claude
- **Код у `vendor/` не читаємо і не правимо руками** (заблоковано в `.claude/settings.json`). Залежності змінюються лише через `composer` (`composer update <pkg>`); платформа зафіксована як PHP 8.1.
- Цільова версія — **PHP 8.1**: без `readonly class`, DNF-типів, `json_validate`, `mb_str_pad`, `#[Override]` та іншого з 8.2+. Перевірка: `php8.1 -l <файл>`.
- Секрети (`config/config.php`, `users/*.key`, `logs/*`, `var/*`) не виводити і не логувати. Шаблон — `config/config.example.php`.
- Не запускати код, що ходить у бойовий Zabbix/Telegram (`index.php`, `scripts/top200.php`, `webHook.php`) без прямої вказівки.
- Перевірка змін: `composer test` (PHPUnit, без мережі) і `composer stan` (PHPStan, рівень 6).
- `.vscode/scp.json` має `uploadOnSave` у прод (`/var/www/zbx-bot-prod/` на 10.0.0.32) — збереження файлу в редакторі = деплой. На проді потрібен `composer install --no-dev -o`.
- Тексти користувачам — українською; PSR-12, `declare(strict_types=1)`, залежності через конструктор (без `global`).

## Структура
- `index.php` — тонка точка входу вебхука → `ZbxBot\Bootstrap::bot()->handle()`.
- `src/Bot/` — `Bot` (секрет вебхука → дедуплікація → доступ → ліміти → маршрут), `Router`, `Commands` (маршрути й обробники), `UserTokens`, `Menu`, `Context`, `Access`.
- `src/Zabbix/` — `ZabbixClient` (єдиний виклик API через `intellitrend/zabbixapi`), `ZabbixService` (user/problem/event/group/token; усе пачками і з кешем).
- `src/Telegram/` — `Messenger` (нарізка на 4096, баланс `<pre>`, склейка блоків), `GuzzleTransport` (Bot API, повтор при 429).
- `src/Cache/` — `FileCache` (файловий кеш у `var/cache`), `RateLimiter`, `UpdateDeduplicator`.
- `src/Storage/TokenStore.php` — `users/<chat_id>.key`: sodium-шифрування при заданому `token_key`, права 0600; читає старий формат і мігрує.
- `src/Net/NetTools.php` + `commands/get_Int_status_cisco2.sh` — `ping`, `cisco`, `apc` (`escapeshellarg` + `timeout`).
- `scripts/top200.php` (cron, тільки CLI) і `src/Top200Sync.php` — синхронізація групи TOP200 різницею.
- `webHook.php` — керування вебхуком, **тільки CLI**: `php webHook.php set|info|del`.
- `tests/` — PHPUnit. Логи — `logs/<chat_id>-YYYY-MM-DD.log` (ротація 30 днів), `logs/main-*.log`.

## Доступ і кеш
- Користувач = Zabbix-юзер із медіа Telegram, `sendto` = chat id. Список кешується у `var/cache` на `user_cache_ttl` (300 с): відкликання доступу діє з затримкою до 5 хв; адмін може виконати `/reset`.
- Якщо Zabbix недоступний, використовується застарілий кеш користувачів.
- Команда `/tkn` видалена: токени більше не показуються в чаті.

## Деплой
1. `composer install --no-dev -o` на сервері; `var/` має бути доступним на запис користувачу веб-сервера.
2. (Рекомендовано) `token_key` у `config.php` — старі `.key` перешифруються при першому читанні.
3. Секрет вебхука — нижче.

## Увімкнення секрету вебхука
1. Додати в `config/config.php` `'webhook_secret' => '<random A-Za-z0-9_->'`.
2. `php webHook.php set` (передасть `secret_token` Telegram), далі `php webHook.php info`.
Порядок важливий: з увімкненим секретом без перереєстрації вебхука бот відповідатиме 403.
