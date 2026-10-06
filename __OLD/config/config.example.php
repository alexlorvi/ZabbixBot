<?php
  return [
    // Add you bot's API key
    'api_key' => 'CHANGE_ME',
    // Add main Zabbix API host and key
    'zbx_host' => 'http://10.254.24.5',
    'zbx_token' => 'CHANGE_ME',
    // When using the getUpdates method, this can be commented out
    'webhook' => [
        'url' => 'https://bots.wog.ua/phpBot/zbx-bot-prod/',
    ],
    // SNMP community (раніше було захардкоджено в commands/get_Int_status_cisco2.sh)
    'snmp_community_cisco' => 'CHANGE_ME',
    'snmp_community_apc' => 'CHANGE_ME',
    // ID типу медіа Telegram у Zabbix (за замовчуванням 16)
    'zbx_mediatype_id' => '16',
    // Секрет вебхука. Вмикати ПІСЛЯ `php webHook.php set` (див. CLAUDE.md):
    // 'webhook_secret' => 'random-A-Za-z0-9_-',

    'admins' => [
        '123456789',
    ],
  ];

