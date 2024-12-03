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
            'menu' => [
                [unichr(0x1F4D6).' Деталізація активних'],
                [unichr(0x1F4CB).' Список активних'],
            ],
            'menuaction' => [
                unichr(0x1F4D6).' Деталізація активних' => [
                    //'class' => '',
                    'method' => 'displayUserEventsFull',
                    'params' => [],
                ],
                unichr(0x1F4CB).' Список активних' => [
                    //'class' => '',
                    'method' => 'displayUserEventsSummary',
                    'params' => [],
                ],
            ],
        ]
    ],
    'user' => [
        'UserEventsSummary' => [
            'Line' => emoji('clock').'%s - /ev%s'.PHP_EOL.
                      emoji('pushpin').'%s (%s)'.PHP_EOL.
                      emoji('preatyline'),
            'Count' => 'Всього не закрито подій - %s',
            'None' => 'У Вас немає відкритих інцидентів',
        ],
        'UserEventsFull' => [
            'Line' => emoji('clock').' %s'.PHP_EOL.
                    emoji('pushpin').'%s (%s)'.PHP_EOL.
                    emoji('preatyline').PHP_EOL.
                    emoji('page').' %s %s'.PHP_EOL.
                    emoji('preatyline').PHP_EOL,
            'ackLine' => emoji('speech').' %s - %s (%s)'.PHP_EOL,
            'Count' => 'Всього не закрито подій - %s',
            'None' => 'У Вас немає відкритих інцидентів',
        ],
        'EventById' => [
            'Line' => emoji('clock')." %s".PHP_EOL.
                      emoji('pushpin')." %s".PHP_EOL.'%s'.PHP_EOL.
                      emoji('preatyline').PHP_EOL.
                      emoji('page').' %s %s'.PHP_EOL.
                      emoji('preatyline').PHP_EOL,
            'ackLine' => emoji('speech').' %s - %s (%s)'.PHP_EOL,
        ],
    ],
    'main' => [
        'dateSec' => '%a днів, %h годин, %i хвилин %s секунд',
    ],
];