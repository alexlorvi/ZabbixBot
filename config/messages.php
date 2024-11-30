<?php

return [
    'command' => [
        'start' => [
            'description' => 'Start Command to get you started',
            'message' => 'Hello %s!'.PHP_EOL.
                         'Welcome to our bot. Your ID is - %s',
        ],
        'help' => [
            'description' => 'Help Command to describe Bot commands',
            'message' => 'Hello *%s*!'.PHP_EOL.'Welcome to our bot. Here are our available commands:',
        ],
        'ping' => [
            'description' => 'Ping Command to check network connectivity',
            'usage' => emoji('warn').' Host or IP not specified.'.PHP_EOL.
                       '*Usage:* /ping {HOST/IP} _{optional You can set count up to 50, default is 4}_'.PHP_EOL.
                       '*Example:*'.PHP_EOL.'/ping google.com'.PHP_EOL.'/ping 8.8.8.8 20',
            'start' => emoji('satelite').' Ping host...',
        ],
    ],
    'user' => [
        'UserEventsSummary' => [
            'Line' => emoji('clock').'%s - /ev%s'.PHP_EOL.
                      emoji('pushpin').'%s (%s)'.PHP_EOL.
                      emoji('preatyline'),
            'Count' => 'Total open events - %s',
            'None' => 'You dont have open events',
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
        'dateSec' => '%a days, %h hours, %i minutes %s seconds',
    ],
];