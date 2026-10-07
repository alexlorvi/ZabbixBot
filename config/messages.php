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
            'badHost' => "Host «%s» does not look like an IP or host name",
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
            'full_button' => unichr(0x1F4D6)." Full Report",
            'summary_button' => unichr(0x1F4CB)." Summary Report",
            'help_button' => "\u{2754} Help",
            'settings_button' => "\u{2699}\u{FE0F} Settings",
            'admin_badge' => "\u{1F511} Status: administrator",
            'reset_button' => "\u{1F504} Reset cache",
        ],
        'settings' => [
            'description' => 'Personal settings: language, notification severity, /menu style',
            'title' => "\u{2699}\u{FE0F} Settings",
            'lang' => 'Language',
            'media_names' => ['tg' => 'Telegram', 'email' => 'Email'],
            'media_missing' => 'This notification method is not configured in Zabbix.',
            'severity' => 'Notification severity',
            'menu_style' => '/menu style',
            'menu_style_inline' => 'Inline buttons',
            'menu_style_reply' => 'Classic keyboard',
            'severity_levels' => ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'],
            'back' => "\u{00AB} Back",
            'close' => 'Close',
        ],
        'host' => [
            'description' => 'Search a Zabbix host by name or IP, show its status and active problems',
            'usage' => "Usage: /host <IP or part of the name>\nExample: /host 10.16.11.5 or /host Kyiv",
            'length' => "The query must be 3 to 64 characters long",
            'notFound' => "Nothing found for «%s»",
            'many' => "Several hosts match «%s». Choose one:",
            'more' => "Showing the first %d, refine the query.",
            'noAccess' => "Host not found or access denied",
            'monitoring' => "Monitoring",
            'monitoringOn' => "enabled \u{2705}",
            'monitoringOff' => "disabled \u{1F6AB}",
            'extraIf' => "extra",
            'tag' => "Tag",
            'location' => "Location",
            'problems' => "Active problems (%s):",
            'noProblems' => "No active problems \u{2705}",
        ],
        'reset' => [
            'description' => 'Admin: reset the Zabbix users/groups cache',
            'done' => "Zabbix users and groups cache cleared",
            'denied' => "Sorry, admins only",
        ],
        'cisco' => [
            'description' => 'Cisco interface/port status over SNMP',
            'wait' => "Please wait, checking...",
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
        'tokenError' => "Could not get your Zabbix API token. Try again later or contact an administrator.",
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
        'rateLimited' => "Too many requests. Please wait a minute.",
        'durUnits' => ['d' => 'd', 'h' => 'h', 'm' => 'm'],
        'dateSec' => '%a days, %h hours, %i minutes %s seconds',
    ],
    'ack' => [
        'button' => "\u{2705} Acknowledge",
        'commentButton' => "\u{1F4AC} Comment",
        'done' => "\u{2705} Problem /ev%s acknowledged",
        'commented' => "\u{1F4AC} Comment added to /ev%s",
        'prompt' => "\u{1F4AC} Comment for /ev%s: send it as a reply to this message",
        'placeholder' => "Comment for Zabbix",
        'error' => "\u{26A0} Zabbix rejected it: %s",
    ],
    'net' => [
        'bad_ip' => "%s does not look like an IP",
        'bad_ipv4' => "%s does not look like an IPv4",
        'failed' => "Something went wrong",
    ],
    'languageNames' => [
        'en' => 'English',
        'ua' => 'Українська',
    ],
];