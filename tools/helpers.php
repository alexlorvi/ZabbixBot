<?php

use ZabbixBot\Services\LoggerService;
use ZabbixBot\Services\ConfigService;

function mainLOG(string $loggerType, string $level,string $message, $context = []) {
    $loggerService = LoggerService::getInstance();
    $loggerService->log($loggerType, $level, $message, $context);
}

function userLOG($userId, $level, $message, $context = []) {
    $loggerService = LoggerService::getInstance();
    $userLogger = $loggerService->createUserLogger($userId);
    $userLogger->log($level, $message, $context);
}

function emoji(string $name, $default = ''):string {
    $msg = ConfigService::getInstance();
    return $msg->getNested('emoji.'.$name) ?? $default;
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

function getNestedFromArray($searchArray,string $path, $default = null):mixed {
    if (!is_array($searchArray)) return $default;
    $keys = explode('.', $path);
    $value = $searchArray;
    foreach ($keys as $key) {
        if (isset($value[$key])) {
            $value = $value[$key];
        } else {
            return $default;
        }
    }
    return $value;
}
