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
    ],
    'emoji' => [
        'warn' => "\xE2\x9A\xA0",
        'satelite' => "\xF0\x9F\x93\xA1",
    ],
];