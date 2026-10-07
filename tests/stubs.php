<?php

// Мінімальні заглушки для тестів, що чіпають MessageService/MessageQueue (без реального логера й конфіга).
if (!defined('USER_PREF_PATH')) {
    define('USER_PREF_PATH', sys_get_temp_dir().'/zbxbot-prefs-'.getmypid());
}
if (!defined('ALERT_PATH')) {
    define('ALERT_PATH', sys_get_temp_dir().'/zbxbot-alerts-'.getmypid());
}
if (!function_exists('fixpath')) {
    function fixpath(string $path): string { return substr($path, -1) === '/' ? $path : $path.'/'; }
}
if (!function_exists('userLOG')) {
    function userLOG($userId, $level, $message): void {}
}
if (!function_exists('mainLOG')) {
    function mainLOG($channel, $level, $message): void {}
}
if (!function_exists('unichr')) {
    function unichr($i) { return iconv('UCS-4LE', 'UTF-8', pack('V', $i)); }
}
if (!function_exists('emoji')) {
    // messages*.php беруть емодзі з конфіга - у тестах вони не важливі
    function emoji(string $name, $default = ''): string { return ''; }
}
