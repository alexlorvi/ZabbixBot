<?php

use ZabbixBot\Services\LoggerService;

function mainLOG(string $loggerType, string $level,string $message, $context = []) {
    $loggerService = LoggerService::getInstance();
    $loggerService->log($loggerType, $level, $message, $context);
}

function userLOG($userId, $level, $message, $context = []) {
    $loggerService = LoggerService::getInstance();
    $userLogger = $loggerService->createUserLogger($userId);
    $userLogger->log($level, $message, $context);
}

/*
 * Simple Functions
 * 
 */

function fixpath(string $path):string {
    return (substr($path,-1) == '/') ? $path : $path.'/';
}

function startsWith($string, $startString) { 
    return substr($string, 0, strlen($startString)) === $startString; 
}

function emoji(string $name):string {
    switch ($name) {
        case 'warn':
            return "\xE2\x9A\xA0";
        case 'satelite':
            return "\xF0\x9F\x93\xA1";
        default:
            return '';
    }
}