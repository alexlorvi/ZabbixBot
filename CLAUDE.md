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

Register/inspect/remove the Telegram webhook via CLI (`app:webhook`, wraps `BotController::registerHook()`/`getWebhookInfo()`/`deleteWebhook()`; `index.php` itself has no CLI branch anymore). If `telegram.webhook_secret` is set in config, it must already be set *before* re-running `app:webhook set`, otherwise Telegram's old webhook registration won't send the secret and the bot will reply 403 to every update.

CLI console app (`console.php`):
```
php console.php app:retry-messages --limit=5     # drain the failed-message queue
php console.php app:send-message <chatId> "text" # send an ad-hoc message
php console.php app:top200-sync                   # sync the TOP200 Zabbix host group (cron)
php console.php app:webhook set|info|del          # register/inspect/remove the Telegram webhook
```

Tests (PHPUnit, no network calls):
```
composer test
```
Single test / filter: `php vendor/bin/phpunit --filter testName tests/SomeTest.php`. Lint a single file: `php -l <file>` (no static analysis tool is configured).

## Configuration

- `config/config.php` is the real, gitignored config (copy `config/config.php.sample` to create it). Sections: `telegram` (bot token, webhook URL/secret, rate limit, proxy, registered command classes), `zabbix` (host/API key, `admin_group`, per-user token TTL and optional `token_key` for encryption, group aliases), `net` (SNMP community strings for `/cisco` and `/apc`), `logger` (paths, levels, retention), `emoji`.
- `config/constants.php` defines path constants (`ROOT_PATH`, `CONF_PATH`, `MSG_PATH`, `LOG_PATH`, `USER_PREF_PATH`, `CACHE_PATH`, `TOKEN_PATH`, `COMMANDS_PATH`) used throughout the app instead of hardcoded paths.
- `ConfigService` (singleton) loads `config/config.php` and exposes `getNested('a.b.c', $default)` dot-path lookups.
- `LangService` (singleton) loads `config/messages.php` plus any `config/messages.<lang>.php` variants (e.g. `messages.ua.php`) and exposes the same `getNested()` dot-path API, falling back to the default language if a key is missing in the active one. Active language is per-user (`telegram.lang` default, overridable per user via preferences).

## Architecture

**Pure, unit-tested helpers** (no network/singletons; i18n passed in): `Router::classify()` (typed text → kind), `Router::classifyCallback()` (inline-button `callback_data` → kind; never `command` — the SDK's `commandsHandler()` parses the *message text* of a `callback_query`, not its data, so `/command` buttons can't go through it; legacy `/ping|cisco|apc <ip>` buttons map to `net`, anything else unknown → `ignored`), `Router::secretValid()` (webhook secret), `PingService::validHost()`/`clampCount()` (host must be an IP/hostname not starting with `-`, otherwise ping parses it as an option), `EventFormatter` (event normalize/format/summary/duration), `CommandList::render()` (`/help` list; commands named in `telegram.admin_commands`, default `['reset']`, are shown only to admins — `UserController::isAdmin()`; `/menu` shows an admin badge and a "Reset cache" button, `menu:reset`, re-checked server-side via `UserController::resetCache()`).

**Request flow (webhook path):** `index.php` → `BotController::handleWebhook()`:
1. Validates the webhook secret (`X-Telegram-Bot-Api-Secret-Token` vs `telegram.webhook_secret`) if configured — returns 403 on mismatch.
2. Reads the update and drops it if `UpdateDeduplicator` has already seen this `update_id` (Telegram retries webhooks that don't answer fast enough).
3. For `message` updates and `callback_query` updates (inline keyboard button presses) alike, resolves `$chatId`/`$text` and calls `handleMessage()`.
4. `handleMessage()` applies a per-chat `RateLimiter`, then `UserController::setUserID()` checks Zabbix authorization via `ZabbixService::isUser()` (cached lookup, see below). If authorized, it applies the user's saved language and lazily issues/renews a Zabbix API token on demand (not eagerly) via `UserTokens`.
5. Authenticated users get extra Telegram commands registered at runtime (`telegram.user_commands` from config) and are routed through a `switch` that recognizes ad-hoc text patterns (`/ev{id}`, `/hostid{id}`, `/{n}sec`, `/{n}h`), the reply-keyboard `/menu` labels (`command.menu.full_button`/`summary_button` — the same strings `MenuCommand` puts on the keyboard) and callback prefixes (`menu:`, `set:`, `net:<ping|cisco|apc>:<ip>` from the `/host` card — dispatched to `PingCommand::run()`/`CiscoCommand::run()`/`ApcCommand::run()`) in addition to normal `/command` dispatch via the Telegram SDK's command handler (typed messages only).
6. Unauthenticated users only get the base `telegram.commands` (e.g. `/start`, `/help`), typed only.

User-visible strings live in `config/messages*.php`, not in code (incl. `NetTools` texts under `net.*`, `/host` card under `command.host.*`).

**Telegram commands** (`app/Commands/*.php`) extend the SDK's `Telegram\Bot\Commands\Command` and are registered via config (`telegram.commands` / `telegram.user_commands`), not autodiscovered. Each pulls its description/usage/reply text from `LangService` rather than hardcoding strings, so adding a command means: create the class, add i18n strings under `command.<name>.*` in both `config/messages.php` and `config/messages.ua.php`, and list the class in `config/config.php`. Current user commands: `/ping`, `/events` (Zabbix group aliases), `/menu`, `/host` (search + inline host card with Ping/Cisco/APC buttons), `/reset` (admin-only, clears Zabbix cache), `/cisco`, `/apc`, `/settings` (personal settings panel, see below).

**CLI commands** (`app/Commands/CLI/*.php`) extend Symfony Console's `Command` and are registered manually in `console.php` via `$application->add(...)`. They are separate from the Telegram command classes above (different base class, different registration mechanism).

**Zabbix access** is centralized in `ZabbixService`, a wrapper over `intellitrend/zabbixapi` (login is cached per token inside the instance, not re-done on every call) with its own `FileCache` (`CACHE_PATH`). `telegramUsers()`/`findUser()` cache the full Zabbix-user-with-Telegram-media list (`zabbix.user_cache_ttl`, default 300s, with stale-cache fallback if Zabbix is down) so `isUser()`/`getUserID()`/`getUserInfo()` cost one Zabbix call per TTL window instead of one per lookup. The same cached lookup also carries each user's `usrgrps` (group names, via `selectUsrgrps`), which `isAdmin()` checks against `zabbix.admin_group` — **admin status comes from actual Zabbix usergroup membership, not a chat-id list in config** (there is no `telegram.admins` key; `/reset` is the only admin-gated command right now). `getGroupIdByName()` caches the group name→id map similarly (`zabbix.group_cache_ttl`). `getUserProblems()` (severity `null` = all levels; group must be an ID — `UserController::getUserEvents()` resolves names via `getGroupIdByName()`) cross-checks `trigger.get` (`monitored`+`active`+`status=0`) so disabled triggers/hosts don't show up as open problems; event details (hosts, acks, tags) come from one batched `getEventsInfo()` call, rendered by `UserController::formatEvent()` (severity, age, host, tags, acks; i18n under `user.severity`, `user.UserEventsFull`, `main.durUnits`). `issueUserToken()` creates-or-renews the per-user `zbx_bot` API token. Host lookups for `/host`: `searchHosts()`, `hostById()`, `hostProblems()`. Group membership management (used by Top200Sync): `getHostsByGroup()`, `massAddHostGroup()`, `massRemoveHostGroup()`. Admins can force-refresh via `resetUserCache()` (`/reset` command).

**Per-user Zabbix API tokens** are stored encrypted (`TokenStore`, sodium secretbox when `zabbix.token_key` is configured, plain JSON with `0600` perms otherwise) under `TOKEN_PATH`, keyed by chat id. `UserTokens` issues a new token on first use and auto-renews it before expiry (`zabbix.user_token_ttl_days`); never written eagerly on every message.

**Messaging** goes through `MessageService::sendMessage()`, which splits messages over Telegram's 4096-char limit (`MessageService::chunk()`, HTML-aware: balances `<pre>` tags across split points) and, on send failure, enqueues the message via `MessageQueue` (a flat JSON file at `preferences/message_queue.json`) for later retry by the `app:retry-messages` CLI command. `sendBlocks()` packs a list of independent text blocks (e.g. one per Zabbix problem) into as few messages as possible instead of one message per block — use it instead of looping `sendMessage()` per item.

**Zabbix → Telegram alerts** (`alert.php`, config `alerts.*`, docs in `docs/zabbix-alerts.md`): a separate HTTP entry point, authenticated by shared secret header `X-Alert-Token` (`alerts.token`; empty = disabled), fed by a Zabbix webhook media type (`docs/zabbix-mediatype.js`). `AlertService::handle()` (pure; transport and known-user check injected as closures, tested) sends via `MessageService::sendMessage()` (now returns the first delivered `message_id`, `null` if queued; option `keep_keyboard` avoids removing the user's reply keyboard) and tracks problems in `AlertStore` (`ALERT_PATH`=`alerts/`, one file `<eventId>_<chatId>.json`, TTL purge `alerts.ttl_days`). If Telegram is unreachable the message is queued with an `_alert` mark ({event_id,chat_id,mode}); `MessageService::retryMessages()` (cron `app:retry-messages`) delivers FIFO, resolves the reply target at delivery time and only then updates `AlertStore` (problem → put id, recovery → delete); while an alert of the same event+chat sits in the queue new ones for it are queued too (ordering); HTTP 400/403 queue items are dropped, other errors stop the run. `MessageQueue` mutations are `flock`ed. `reply_parameters` must be a JSON string (the SDK only stringifies `reply_markup`). Recovery/update messages reply to the stored `message_id` (`reply_parameters`, `allow_sending_without_reply`); recovery deletes the record; no record → plain message. `TelegramFactory::make()` builds the `Api` for both `BotController` and `alert.php`.

**Network diagnostics**: `PingService` (used by `/ping`, streams live output by editing the Telegram message) and `NetTools` (`/cisco` via `commands/get_Int_status_cisco2.sh` over SNMP, `/apc` via ping+SNMP+nmap port check, HTML output; `NetTools::fromConfig()` builds it with `net.*` config and current-language texts) are separate services — `NetTools` does not duplicate ping. Each command exposes `run()` so the `/host` card buttons reuse it.

**Top200Sync**: `Top200Sync::plan()` is a pure function that diffs the wanted TOP200 Zabbix group membership (derived from `WogRouters` host inventory tags 1-200, plus a hardcoded extra host) against current membership. `app:top200-sync` CLI command applies the diff via `ZabbixService`; intended for cron.

**Logging** goes through `LoggerService` (Monolog-based singleton): a shared `main`/`zabbix` logger plus one lazily-created per-user log file (`logs/user_<id>.log`), both using `RotatingFileHandler` (`logger.keep_days`, default 30). Use the global helpers `mainLOG($channel, $level, $message)` and `userLOG($userId, $level, $message)` from `tools/helpers.php` rather than instantiating loggers directly.

**User preferences** (`app/Models/User.php`) are a flat JSON key/value store per Telegram user ID (`preferences/user_<id>.json`): `lang` and `menu_style` (`inline` default, or `reply`). Zabbix API tokens live separately in `TokenStore` (see above), not here.

**Personal settings panel** (`/settings`, `SettingsCommand`) is driven entirely by `callback_query` + `MessageService::editMessage()` (wraps `editMessageText`) so every change edits the same message instead of sending new ones. The main panel has language, `/menu` style, one button per configured notification method (`tg` = `zabbix.mediatype_id`, `email` = Zabbix mediatype type 0; only shown if the user has such a media) and "Close" (deletes the message via `MessageService::deleteMessage()`; no "Back" here). Each media button opens a submenu (`set:media:<kind>`) with severity checkboxes (`set:sev:<kind>:<bit>`) and a "Back" (`set:main`). Notification severity is **not** a local event filter — toggling a checkbox writes the severity bitmask (0=Not classified … 5=Disaster, bit per level) to the user's media of that kind only via `ZabbixService::updateUserMediaSeverity($userId,$mask,$mediaTypeIds)` (`user.update`, service API key — a user's personal token can't edit their own `user` object). The target `userid` always comes from `UserController::getZabbixUserId()` (the authenticated chat), never from `callback_data`, so a crafted callback can't change another user's alerting. `SettingsCommand::renderFromState()` / `renderMediaFromState()` are pure functions (no `LangService`/network) building the main panel / media submenu text+keyboard from given state — that's what's unit tested, not the live `render()`/`editOpen()` wrappers. `MenuCommand::renderInline()`/`editOpenInline()` is the symmetric "Back" target. Routing lives in `BotController::handleMessage()`'s switch as the `menu:*` / `set:*` text-prefix cases (callback data, not real `/commands`), so it must stay ahead of the generic `!str_starts_with($text,'/')` catch-all case.

## Workflow

After finishing each stage of work: update `README.md` (user-facing behaviour) and this file's architecture/backlog sections (`## Current state / open backlog

Feature ideas and the ScriptServer integration plan live in [docs/ROADMAP.md](docs/ROADMAP.md) — keep them there, this section is for technical debt and known defects.

The 2026-10-07 review bugs are fixed (message lost when `sendChatAction` failed offline, dead `/host` card buttons, `/zabbixFull` alias, English reply-keyboard `/menu`, `print_r` in the webhook, `/ping` option injection, `exit` on missing token, hard-coded Ukrainian strings, duplicated `/reset` check). Remaining:
- No test for `ZabbixService` network paths (`getUserProblems()`, `event.get` batching, login caching) or for the side-effecting part of `BotController::handleMessage()` — the pure parts (`Router`, `EventFormatter`, `CommandList`, `PingService` validation, `FileCache`/`RateLimiter`/`UpdateDeduplicator`) are covered. The old client's tests relied on an injectable Transport interface; adding a transport seam to `ZabbixService::request()` would close this.
- Minor leftovers: `require-dev` `symfony/var-dumper` is unused; `ZabbixService::getHostsByGroup()` selects `groups` nobody reads; `getGroups()` params are only ever called with defaults; `/start` and `/help` descriptions are built in the default language (base commands are constructed before the user's language is applied).

Local `config/config.php` (gitignored): `zabbix.admin_group` is the real Zabbix group name (admin status = membership, verified in prod); `net.snmp_community_cisco` is still empty (so `/cisco` errors) — pending human input. `config/config.php.sample` is the structural source of truth — when adding a new config key or registering a new command class, add it there too, otherwise `config.php` silently drifts out of sync.
