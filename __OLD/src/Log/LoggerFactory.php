<?php
declare(strict_types=1);

namespace ZbxBot\Log;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    public function __construct(private readonly string $dir, private readonly int $keepDays = 30)
    {
    }

    public function main(): Logger
    {
        return $this->make('main');
    }

    public function chat(string $chatId): Logger
    {
        return $this->make(preg_replace('/[^0-9-]/', '', $chatId) ?: 'main');
    }

    private function make(string $name): Logger
    {
        $logger = new Logger('');
        $logger->pushHandler(new RotatingFileHandler($this->dir.'/'.$name.'.log', $this->keepDays, Level::Info, true, 0640));
        return $logger;
    }
}
