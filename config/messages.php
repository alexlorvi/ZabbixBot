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
            ],
            'full_button' => unichr(0x1F4D6)." Full Report",
            'summary_button' => unichr(0x1F4CB)." Summary Report",
            'help_button' => "\u{2754} Help",
            'settings_button' => "\u{2699}\u{FE0F} Settings",
        ],
        'settings' => [
            'description' => 'Personal settings: language, notification severity, /menu style',
            'title' => "\u{2699}\u{FE0F} Settings",
            'lang' => 'Language',
            'media' => 'Notification method',
            'media_names' => ['tg' => 'Telegram', 'email' => 'Email'],
            'media_missing' => 'This notification method is not configured in Zabbix.',
            'severity' => 'Notification severity',
            'menu_style' => '/menu style',
            'menu_style_inline' => 'Inline buttons',
            'menu_style_reply' => 'Classic keyboard',
            'severity_levels' => ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'],
            'back' => "\u{00AB} Back",
            'close' => 'Close',
            'closed' => 'Settings closed.',
            'updated' => 'Settings updated.',
            'zabbix_error' => 'Could not update settings in Zabbix. Try again later.',
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
        'severity' => [
            0 => "\u{26AA} Not classified",
            1 => "\u{1F535} Information",
            2 => "\u{1F7E1} Warning",
            3 => "\u{1F7E0} Average",
            4 => "\u{1F534} High",
            5 => "\u{1F7E3} Disaster",
        ],
        'UserEventsSummary' => [
            'Line' => '%s %s - /ev%s'.PHP_EOL.
                      emoji('pushpin').' %s (%s)'.PHP_EOL.
                      emoji('page').' %s',
            'Count' => 'Total open events - %s',
            'None' => 'You dont have open events',
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
            'Count' => 'Total open events - %s',
            'None' => 'You dont have open events',
        ],
    ],
    'main' => [
        'durUnits' => ['d' => 'd', 'h' => 'h', 'm' => 'm'],
        'dateSec' => '%a days, %h hours, %i minutes %s seconds',
    ],
    'languageNames' => [
        'en' => 'English',
        'ua' => 'Українська',
    ],
];