# zbx-bot

Telegram-бот для Zabbix (PHP 8.1+, webhook). Команди: `/menu`, списки/деталі Disaster-проблем (в т.ч. по групах TOP200, Gold_AZS, OilBases, `/24h`, `/72h`), `/ev<id>`, `/ping`, `/cisco`, `/apc`.
Доступ — лише для користувачів Zabbix із медіа Telegram. Архітектура, деплой і правила — у [CLAUDE.md](CLAUDE.md).

    composer install        # залежності
    composer test           # PHPUnit
    composer stan           # PHPStan
    cp config/config.example.php config/config.php
