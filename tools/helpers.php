<?php

function startsWith($string, $startString) { 
    return substr($string, 0, strlen($startString)) === $startString; 
}

function emoji(string $name):string {
    switch ($name) {
        case 'warn':
            return "\xE2\x9A\xA0";
        default:
            return '';
    }
}