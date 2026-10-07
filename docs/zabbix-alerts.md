# Сповіщення з Zabbix у Telegram (alert.php)

`alert.php` приймає сповіщення від Zabbix і шле їх користувачам у Telegram. Для проблеми запам'ятовується
`message_id`; сповіщення про відновлення (і оновлення) приходить **відповіддю** на повідомлення про проблему.

## Як це працює

| Подія в Zabbix | Що робить бот | Запис у `alerts/` |
|---|---|---|
| Проблема (`event_value=1`) | звичайне повідомлення | створюється `<eventId>_<chatId>.json` з `message_id` |
| Оновлення (`event_update_status=1`) | відповідь на повідомлення про проблему | лишається |
| Відновлення (`event_value=0`) | відповідь на повідомлення про проблему | видаляється |
| Відновлення, а запису немає (бот не бачив проблему / запис прострочений) | звичайне повідомлення | — |

Нотатки:
- У повідомленні про відновлення `{EVENT.ID}` — це id **початкової проблеми**, саме за ним шукається запис.
- Записи проблем, що так і не відновились, видаляються через `alerts.ttl_days` (за замовчуванням 30), чистка йде
  самостійно, не частіше за раз на годину.
- Якщо Telegram/проксі недоступні, сповіщення потрапляє в чергу (`preferences/message_queue.json`) з міткою події.
  `app:retry-messages` (cron) доставляє чергу по порядку, і **після доставки** запам'ятовує `message_id` проблеми;
  для відновлення/оновлення в черзі відповідь на проблему визначається в момент доставки. Поки в черзі є
  сповіщення цієї події для цього користувача, нові сповіщення цієї події теж стають у чергу, щоб відновлення не
  обігнало проблему. Назавжди відхилені Telegram повідомлення (HTTP 400/403, напр. бот заблокований) відкидаються.
- Reply-клавіатуру користувача сповіщення не знімають.
- Якщо початкове повідомлення видалили в чаті, відповідь все одно відправляється (`allow_sending_without_reply`).

## Налаштування бота

У `config/config.php` (структура — `config/config.php.sample`):

```php
'alerts' => [
    'token' => 'довгий-випадковий-секрет',   // порожній = alert.php вимкнений (503)
    'ttl_days' => 30,
    'require_known_user' => true,            // слати лише користувачам Zabbix з медіа Telegram
],
```

Каталог `alerts/` є в репозиторії (файли `*.json` ігноруються git); він має бути доступний на запис користувачу PHP (як `tokens/`).

Cron для черги: `* * * * * php /path/console.php app:retry-messages --limit=50`.

## Налаштування Zabbix

*Alerts → Media types → Create media type*: Type = **Webhook**, Script = вміст
[`zabbix-mediatype.js`](zabbix-mediatype.js), параметри (Name → Value):

| Name | Value |
|---|---|
| `url` | `https://<host>/alert.php` |
| `token` | значення `alerts.token` |
| `sendto` | `{ALERT.SENDTO}` |
| `subject` | `{ALERT.SUBJECT}` |
| `message` | `{ALERT.MESSAGE}` |
| `event_id` | `{EVENT.ID}` |
| `event_value` | `{EVENT.VALUE}` |
| `event_update_status` | `{EVENT.UPDATE.STATUS}` |
| `parse_mode` | *(порожньо, `html`, `markdown`, `markdownv2`)* |

У користувача Zabbix у *Media* — цей медіатип, **Send to** = Telegram chat id. Дію (Action) налаштуйте з операціями
для проблеми та **recovery operations** (і за потреби update operations) на цей медіатип.

## Формат запиту

`POST alert.php`, `Content-Type: application/json`, заголовок `X-Alert-Token`.

| Поле | Опис |
|---|---|
| `sendto` | chat id отримувача (обов'язкове) |
| `subject`, `message` | текст; хоча б одне непорожнє. Склеюються через перенос рядка |
| `event_id` | id події; без нього сповіщення просто відправляється без відстеження |
| `event_value` | `1` проблема (за замовчуванням), `0` відновлення |
| `event_update_status` | `1` — оновлення проблеми |
| `parse_mode` | `html` / `markdown` / `markdownv2`, інше ігнорується |

Відповідь: `200 {"ok":true,"message_id":123,"mode":"recovery+reply"}`; помилки: `400` (поля), `401` (токен),
`403` (отримувач не користувач Zabbix), `405`, `503` (alerts.token не задано).

Перевірка вручну:
```
curl -s -X POST https://<host>/alert.php -H 'X-Alert-Token: <token>' -H 'Content-Type: application/json' \
  -d '{"sendto":"<chat id>","subject":"Test","message":"Hello","event_id":"1","event_value":"1"}'
```
