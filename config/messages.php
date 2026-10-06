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
        'events' => [
            'description' => 'Events Command to get event by Group Name',
            'usage' => emoji('warn').' Group name not specified.'.PHP_EOL.
                       '*Usage:* /event {GroupName} _{full|list (its default option)}_'.PHP_EOL.
                       '*Example:*'.PHP_EOL.'/event Zabbix'.PHP_EOL.
                       'Availiable aliases predefined in config:'.PHP_EOL,
        ],
        'menu' => [
            'description' => 'Menu keyboard',
            'message' => 'Choose Your report:',
            'menu' => [
                [unichr(0x1F4D6)." Full Report"],
                [unichr(0x1F4CB)." Summary Report"],
            ]
        ],
        'host' => [
            'description' => 'Search a Zabbix host by name or IP, show its status and active problems',
        ],
        'reset' => [
            'description' => 'Admin: reset the Zabbix users/groups cache',
        ],
        'cisco' => [
            'description' => 'Cisco interface/port status over SNMP',
            'usage' => emoji('warn').' IP not specified.'.PHP_EOL.
                       '*Usage:* /cisco {IP}'.PHP_EOL.
                       '*Example:*'.PHP_EOL.'/cisco 10.16.11.5',
        ],
        'apc' => [
            'description' => 'APC management card diagnostic (ping/SNMP/port check)',
            'usage' => emoji('warn').' IP not specified.'.PHP_EOL.
                       '*Usage:* /apc {IP}'.PHP_EOL.
                       '*Example:*'.PHP_EOL.'/apc 10.16.11.5',
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
        'UserEventsFull' => [
            'Line' => emoji('clock').' %s'.PHP_EOL.
                    emoji('pushpin').'%s (%s)'.PHP_EOL.
                    emoji('preatyline').PHP_EOL.
                    emoji('page').' %s %s'.PHP_EOL.
                    emoji('preatyline').PHP_EOL,
            'ackLine' => emoji('speech').' %s - %s (%s)'.PHP_EOL,
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