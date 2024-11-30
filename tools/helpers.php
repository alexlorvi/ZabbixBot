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

function unichr($i) {
    return iconv('UCS-4LE', 'UTF-8', pack('V', $i));
}

function splitUnicodeString($string, $maxLength = 4096):array {
    $chunks = [];
    $currentChunk = '';
    // Split the string by lines first
    $lines = preg_split('/\R/', $string);
    foreach ($lines as $line) {
        // If adding this line exceeds the length limit, add the current chunk to the chunks array
        if (mb_strlen($currentChunk . PHP_EOL . $line) > $maxLength) {
            $chunks[] = $currentChunk;
            $currentChunk = '';
        }
        // If the line itself is longer than the max length, split it by spaces
        while (mb_strlen($line) > $maxLength) {
            // Find the position to split the line
            $splitPos = mb_strrpos(mb_substr($line, 0, $maxLength + 1), ' ');
            if ($splitPos === false) {
                $splitPos = $maxLength;
            }
            $chunks[] = mb_substr($line, 0, $splitPos);
            $line = mb_substr($line, $splitPos + 1);
        }
        // Add the line to the current chunk
        $currentChunk .= ($currentChunk === '' ? '' : PHP_EOL) . $line;
    }
    // Add the last chunk if not empty
    if ($currentChunk !== '') {
        $chunks[] = $currentChunk;
    }
    return $chunks;
}

/*
 * Simple Functions
 * 
 */

function fixpath(string $path):string {
    return (substr($path,-1) == '/') ? $path : $path.'/';
}

/* function startsWith($string, $startString) { 
    return substr($string, 0, strlen($startString)) === $startString; 
}

function endsWith($string, $endString) { 
    return substr($string, 0, strlen($startString)) === $startString; 
} */

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
