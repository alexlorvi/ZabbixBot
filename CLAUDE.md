# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

A PHP Telegram bot that bridges Telegram and Zabbix: registered Zabbix users (matched via a Telegram media/`sendto` entry in Zabbix) can query their Zabbix problems/events, search hosts, and run basic network diagnostics from Telegram, while the bot can notify them of alerts. It runs both as a Telegram webhook (`index.php`) and as a CLI app (`console.php`) for background/queue/cron jobs.

Namespace root: `ZabbixBot\` → `app/` (PSR-4, see `composer.json`).

## Commands

Install dependencies:
```
composer install
cp config/config.php.sample config/config.php   # then fill in real secrets
```

Run the webhook entry point (normally invoked by the web server, not directly):
```
php index.php
```

Register the Telegram webhook (uncomment the CLI block in `index.php`, or call `BotController::registerHook()` another way). If `telegram.webhook_secret` is set in config, it must already be set *before* re-registering, otherwise Telegram's old webhook registration won't send the secret and the bot will reply 403 to every update.

CLI console app (`console.php`):
```
php console.php app:retry-messages --limit=5     # drain the failed-message queue
php console.php app:send-message <chatId> "text" # send an ad-hoc message
php console.php app:top200-sync                   # sync the TOP200 Zabbix host group (cron)
```

Tests (PHPUnit, no network calls):
```
composer test
```
Single test / filter: `php vendor/bin/phpunit --filter testName tests/SomeTest.php`. Lint a single file: `php -l <file>` (no static analysis tool is configured).

## Configuration

- `config/config.php` is the real, gitignored config (copy `config/config.php.sample` to create it). Sections: `telegram` (bot token, webhook URL/secret, rate limit, proxy, registered command classes, `admins`), `zabbix` (host/API key, per-user token TTL and optional `token_key` for encryption, group aliases), `net` (SNMP community strings for `/cisco` and `/apc`), `logger` (paths, levels, retention), `emoji`.
- `config/constants.php` defines path constants (`ROOT_PATH`, `CONF_PATH`, `MSG_PATH`, `LOG_PATH`, `USER_PREF_PATH`, `CACHE_PATH`, `TOKEN_PATH`, `COMMANDS_PATH`) used throughout the app instead of hardcoded paths.
- `ConfigService` (singleton) loads `config/config.php` and exposes `get()`/`getNested('a.b.c', $default)` dot-path lookups.
- `LangService` (singleton) loads `config/messages.php` plus any `config/messages.<lang>.php` variants (e.g. `messages.ua.php`) and exposes the same `get()`/`getNested()` dot-path API, falling back to the default language if a key is missing in the active one. Active language is per-user (`telegram.lang` default, overridable per user via preferences).

## Architecture

**Request flow (webhook path):** `index.php` → `BotController::handleWebhook()`:
1. Validates the webhook secret (`X-Telegram-Bot-Api-Secret-Token` vs `telegram.webhook_secret`) if configured — returns 403 on mismatch.
2. Reads the update and drops it if `UpdateDeduplicator` has already seen this `update_id` (Telegram retries webhooks that don't answer fast enough).
3. For `message` updates and `callback_query` updates (inline keyboard button presses) alike, resolves `$chatId`/`$text` and calls `handleMessage()`.
4. `handleMessage()` applies a per-chat `RateLimiter`, then `UserController::setUserID()` checks Zabbix authorization via `ZabbixService::isUser()` (cached lookup, see below). If authorized, it applies the user's saved language and lazily issues/renews a Zabbix API token on demand (not eagerly) via `UserTokens`.
5. Authenticated users get extra Telegram commands registered at runtime (`telegram.user_commands` from config) and are routed through a `switch` that recognizes ad-hoc text patterns (`/ev{id}`, `/hostid{id}`, `/{n}sec`, `/{n}h`) in addition to normal `/command` dispatch via the Telegram SDK's command handler.
6. Unauthenticated users only get the base `telegram.commands` (e.g. `/start`, `/help`).

**Telegram commands** (`app/Commands/*.php`) extend the SDK's `Telegram\Bot\Commands\Command` and are registered via config (`telegram.commands` / `telegram.user_commands`), not autodiscovered. Each pulls its description/usage text from `LangService` rather than hardcoding strings, so adding a command means: create the class, add i18n strings under `command.<name>.*` in both `config/messages.php` and `config/messages.ua.php`, and list the class in `config/config.php`. Current user commands: `/ping`, `/events` (Zabbix group aliases), `/menu`, `/host` (search + inline host card with Ping/Cisco/APC buttons), `/reset` (admin-only, clears Zabbix cache), `/cisco`, `/apc`, `/settings` (personal settings panel, see below).

**CLI commands** (`app/Commands/CLI/*.php`) extend Symfony Console's `Command` and are registered manually in `console.php` via `$application->add(...)`. They are separate from the Telegram command classes above (different base class, different registration mechanism).

**Zabbix access** is centralized in `ZabbixService`, a wrapper over `intellitrend/zabbixapi` with its own `FileCache` (`CACHE_PATH`). `telegramUsers()`/`findUser()` cache the full Zabbix-user-with-Telegram-media list (`zabbix.user_cache_ttl`, default 300s, with stale-cache fallback if Zabbix is down) so `isUser()`/`getUserID()`/`getUserInfo()` cost one Zabbix call per TTL window instead of one per lookup. `getGroupIdByName()` caches the group name→id map similarly (`zabbix.group_cache_ttl`). `getUserProblems()` cross-checks `trigger.get` so disabled/unmonitored triggers don't show up as open problems. `issueUserToken()` creates-or-renews the per-user `zbx_bot` API token. Host lookups for `/host`: `searchHosts()`, `hostById()`, `hostProblems()`. Group membership management (used by Top200Sync): `getHostsByGroup()`, `massAddHostGroup()`, `massRemoveHostGroup()`. Admins can force-refresh via `resetUserCache()` (`/reset` command).

**Per-user Zabbix API tokens** are stored encrypted (`TokenStore`, sodium secretbox when `zabbix.token_key` is configured, plain JSON with `0600` perms otherwise) under `TOKEN_PATH`, keyed by chat id. `UserTokens` issues a new token on first use and auto-renews it before expiry (`zabbix.user_token_ttl_days`); never written eagerly on every message.

**Messaging** goes through `MessageService::sendMessage()`, which splits messages over Telegram's 4096-char limit (`MessageService::chunk()`, HTML-aware: balances `<pre>` tags across split points) and, on send failure, enqueues the message via `MessageQueue` (a flat JSON file at `preferences/message_queue.json`) for later retry by the `app:retry-messages` CLI command. `sendBlocks()` packs a list of independent text blocks (e.g. one per Zabbix problem) into as few messages as possible instead of one message per block — use it instead of looping `sendMessage()` per item.

**Network diagnostics**: `PingService` (used by `/ping`, streams live output by editing the Telegram message) and `NetTools` (`/cisco` via `commands/get_Int_status_cisco2.sh` over SNMP, `/apc` via ping+SNMP+nmap port check, HTML output) are separate services — `NetTools` does not duplicate ping.

**Top200Sync**: `Top200Sync::plan()` is a pure function that diffs the wanted TOP200 Zabbix group membership (derived from `WogRouters` host inventory tags 1-200, plus a hardcoded extra host) against current membership. `app:top200-sync` CLI command applies the diff via `ZabbixService`; intended for cron.

**Logging** goes through `LoggerService` (Monolog-based singleton): a shared `main`/`zabbix` logger plus one lazily-created per-user log file (`logs/user_<id>.log`), both using `RotatingFileHandler` (`logger.keep_days`, default 30). Use the global helpers `mainLOG($channel, $level, $message)` and `userLOG($userId, $level, $message)` from `tools/helpers.php` rather than instantiating loggers directly.

**User preferences** (`app/Models/User.php`) are a flat JSON key/value store per Telegram user ID (`preferences/user_<id>.json`): `lang` and `menu_style` (`inline` default, or `reply`). Zabbix API tokens live separately in `TokenStore` (see above), not here.

**Personal settings panel** (`/settings`, `SettingsCommand`) is driven entirely by `callback_query` + `MessageService::editMessage()` (wraps `editMessageText`) so every change edits the same message instead of sending new ones. It covers language, notification severity, and `/menu` style. Notification severity is **not** a local event filter — toggling a checkbox writes the severity bitmask (0=Not classified … 5=Disaster, bit per level) to **every** configured notification method (media) of the Zabbix user via `ZabbixService::updateUserMediaSeverity()` (`user.update`, service API key — a user's personal token can't edit their own `user` object). The target `userid` always comes from `UserController::getZabbixUserId()` (the authenticated chat), never from `callback_data`, so a crafted callback can't change another user's alerting. `SettingsCommand::renderFromState()` is a pure function (no `LangService`/network) building the panel's text+keyboard from given state — that's what's unit tested, not the live `render()`/`editOpen()` wrappers. `MenuCommand::renderInline()`/`editOpenInline()` is the symmetric "Back" target. Routing lives in `BotController::handleMessage()`'s switch as the `menu:*` / `set:*` text-prefix cases (callback data, not real `/commands`), so it must stay ahead of the generic `!str_starts_with($text,'/')` catch-all case.

## Current state / open backlog

Carried over from `temp/TODO.md`, not yet implemented — scope them individually rather than assuming they're related:
- Favorite commands and auto-delete-alarms-after-N-days (language/severity/menu-style are done, see above) — would extend `app/Models/User.php`. Auto-delete additionally needs a message-tracking store (see reply-to-recover below) since nothing currently records which Telegram message_id an alert was sent as.
- `app:uphost` scheduled CLI command: ping a host repeatedly, notify on recovery or timeout.
- An outbound "send alarm" API with reply-to-recover: track the Telegram `message_id` of a sent alert so a user's reply to it can be matched back to the original event. Needs a new small file-based store (same pattern as `TokenStore`) and a new branch in `BotController` to inspect `message.reply_to_message`.
- ScriptServer integration: external system, config shape (URL/auth/script name/params) not yet specified — needs clarification before implementation.

Known minor gaps from the zbx-bot-prod port (low priority, not blocking): `ZabbixService::request()` re-authenticates on every call instead of caching the login like the old client did when the token is unchanged; there's no CLI equivalent of the old client's `webhook info`/`webhook del` actions (only registration, via `BotController::registerHook()`). `BotController`'s webhook dispatch also has no automated test coverage (webhook secret check, dedup, rate limit, admin-only routing) — the old client's equivalent tests relied on an injectable Transport interface that the SDK-based dispatch here doesn't have.
