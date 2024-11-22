<?php

use ZabbixBot\Services\LoggerService;
use ZabbixBot\Services\MsgService;

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
    $msg = MsgService::getInstance();
    return $msg->get('emoji') ?? $default;
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

function getNestedFromArray(array $searchArray,string $path, $default = null):mixed {
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
