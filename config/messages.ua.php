<?php

return [
    'command' => [
        'start' => [
            'description' => 'Команда Start для початку роботи з Ботом.',
            'message' => 'Вітаю %s!'.PHP_EOL.
                         'Ласкаво просимо. Твій ID - %s',
        ],
        'help' => [
            'description' => 'Команда Help описує все, що може даний Бот',
            'message' => 'Вітаю *%s*!'.PHP_EOL.'Ласкаво просимо до Боту. Він вміє наступні команди:',
        ],
        'ping' => [
            'description' => 'Команда Ping перевіряє доступність хоста в мережі',
            'usage' => emoji('warn').' Імя хоста чи його IP не вказано.'.PHP_EOL.
                       '*Використання:* /ping {HOST/IP} _{можна вказати к-сть пакетів, зазвичай їх 4}_'.PHP_EOL.
                       '*Приклади:*'.PHP_EOL.'/ping google.com'.PHP_EOL.'/ping 8.8.8.8 20',
            'start' => emoji('satelite').' Ping host...',
            'badHost' => "Хост «%s» не схожий на IP чи ім'я хоста",
        ],
        'events' => [
            'description' => 'Команда Events щоб отримувати відкриті події по імені Zabbix-групи',
            'usage' => emoji('warn').' Не вказано імя групи.'.PHP_EOL.
                       '*Використання:* /event {GroupName} _{full|list (за-замовчуванням)}_'.PHP_EOL.
                       '*Приклад:*'.PHP_EOL.'/event Zabbix'.PHP_EOL.
                       'Доступні аліаси, налаштовані в конфігурації:'.PHP_EOL,
        ],
        'menu' => [
            'description' => 'Клавіатура вибору базових звітів',
            'message' => 'Виберіть потрібну опцію:',
            'full_button' => unichr(0x1F4D6).' Деталізація активних',
            'summary_button' => unichr(0x1F4CB).' Список активних',
            'help_button' => "\u{2754} Довідка",
            'settings_button' => "\u{2699}\u{FE0F} Налаштування",
            'admin_badge' => "\u{1F511} Статус: адміністратор",
            'reset_button' => "\u{1F504} Скинути кеш",
        ],
        'settings' => [
            'description' => 'Персональні налаштування: мова, рівень критичності сповіщень, стиль /menu',
            'title' => "\u{2699}\u{FE0F} Налаштування",
            'lang' => 'Мова',
            'media_names' => ['tg' => 'Telegram', 'email' => 'Пошта'],
            'media_missing' => 'Цей спосіб сповіщення не налаштований у Zabbix.',
            'severity' => 'Рівень критичності сповіщень',
            'menu_style' => 'Стиль /menu',
            'menu_style_inline' => 'Інлайн-кнопки',
            'menu_style_reply' => 'Звичайна клавіатура',
            'severity_levels' => ['Не класифіковано', 'Інформація', 'Попередження', 'Середня', 'Висока', 'Аварія'],
            'back' => "\u{00AB} Назад",
            'close' => 'Закрити',
        ],
        'host' => [
            'description' => 'Пошук хоста Zabbix за іменем чи IP, стан і активні проблеми',
            'usage' => "Використання: /host <IP або частина імені>\nНаприклад: /host 10.16.11.5 або /host Київ",
            'length' => "Запит має бути від 3 до 64 символів",
            'notFound' => "Нічого не знайдено за «%s»",
            'many' => "Знайдено кілька хостів за «%s». Оберіть:",
            'more' => "Показано перші %d, уточніть запит.",
            'noAccess' => "Хост не знайдено або немає доступу",
            'monitoring' => "Моніторинг",
            'monitoringOn' => "увімкнено \u{2705}",
            'monitoringOff' => "вимкнено \u{1F6AB}",
            'extraIf' => "дод.",
            'tag' => "Тег",
            'location' => "Розташування",
            'problems' => "Активні проблеми (%s):",
            'noProblems' => "Активних проблем немає \u{2705}",
        ],
        'reset' => [
            'description' => 'Адміну: скинути кеш користувачів/груп Zabbix',
            'done' => "Кеш користувачів і груп очищено",
            'denied' => "Га? Це лише для адмінів",
        ],
        'cisco' => [
            'description' => 'Стан портів Cisco через SNMP',
            'wait' => "Зачекайте. Пробую...",
            'usage' => emoji('warn').' Не вказано IP.'.PHP_EOL.
                       '*Використання:* /cisco {IP}'.PHP_EOL.
                       '*Приклад:*'.PHP_EOL.'/cisco 10.16.11.5',
        ],
        'apc' => [
            'description' => 'Діагностика карти керування APC (ping/SNMP/порти)',
            'usage' => emoji('warn').' Не вказано IP.'.PHP_EOL.
                       '*Використання:* /apc {IP}'.PHP_EOL.
                       '*Приклад:*'.PHP_EOL.'/apc 10.16.11.5',
        ],
    ],
    'user' => [
        'tokenError' => "Не вдалося отримати ваш API-токен Zabbix. Спробуйте пізніше або зверніться до адміністратора.",
        'severity' => [
            0 => "\u{26AA} Не класифіковано",
            1 => "\u{1F535} Інформація",
            2 => "\u{1F7E1} Попередження",
            3 => "\u{1F7E0} Середня",
            4 => "\u{1F534} Висока",
            5 => "\u{1F7E3} Аварія",
        ],
        'UserEventsSummary' => [
            'Line' => '%s %s - /ev%s'.PHP_EOL.
                      emoji('pushpin').' %s (%s)'.PHP_EOL.
                      emoji('page').' %s',
            'Count' => 'Всього не закрито подій - %s',
            'None' => 'У Вас немає відкритих інцидентів',
        ],
        'UserEventsFull' => [
            // args: severity, clock, open for, host name, host, event id, problem name, ack mark
            'Line' => '%s'.PHP_EOL.
                      emoji('clock').' %s (%s)'.PHP_EOL.
                      emoji('pushpin').' %s (%s)'.PHP_EOL.
                      '/ev%s'.PHP_EOL.
                      emoji('page').' %s %s',
            'tagsLine' => PHP_EOL."\u{1F3F7} %s",
            'ackLine' => PHP_EOL.emoji('speech').' %s - %s (%s)',
            'Count' => 'Всього не закрито подій - %s',
            'None' => 'У Вас немає відкритих інцидентів',
        ],
    ],
    'main' => [
        'rateLimited' => "Забагато запитів. Зачекайте хвилину.",
        'durUnits' => ['d' => 'д', 'h' => 'г', 'm' => 'хв'],
        'dateSec' => '%a днів, %h годин, %i хвилин %s секунд',
    ],
    'net' => [
        'bad_ip' => "Щось оце %s не схоже на IP",
        'bad_ipv4' => "Щось оце %s не схоже на IPv4",
        'failed' => "Щось пішло не так",
    ],
    'languageNames' => [
        'en' => 'English',
        'ua' => 'Українська',
    ],
];