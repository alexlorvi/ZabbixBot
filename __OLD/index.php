<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

ini_set('display_errors', '0');
$config = require __DIR__.'/config/config.php';

$status = ZbxBot\Bootstrap::bot($config, __DIR__)->handle(
    (string)file_get_contents('php://input'),
    $_SERVER,
    // Віддаємо Telegram 200 одразу: довгі команди інакше перевищують таймаут вебхука, і update приходить повторно
    function (): void {
        ignore_user_abort(true);
        set_time_limit(180);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
);
http_response_code($status);
