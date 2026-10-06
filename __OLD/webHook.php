<?php
  // Керування вебхуком Telegram. ТІЛЬКИ з консолі:
  //   php webHook.php set|info|del
  // Раніше скрипт був доступний по HTTP і віддавав токен бота у відповіді.
  if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
  }

  $config = require __DIR__ . '/config/config.php';
  $method = $argv[1] ?? '';
  $params = [];

  switch ($method) {
    case 'set':
      $endpoint = 'setWebhook';
      $params = [
        'url' => $config['webhook']['url'],
        'allowed_updates' => json_encode(['message', 'callback_query']),
      ];
      if (!empty($config['webhook_secret'])) {
        $params['secret_token'] = $config['webhook_secret'];
      }
      break;
    case 'info':
      $endpoint = 'getWebhookInfo';
      break;
    case 'del':
      $endpoint = 'deleteWebhook';
      break;
    default:
      fwrite(STDERR, "Usage: php webHook.php set|info|del\n");
      exit(1);
  }

  $ch = curl_init('https://api.telegram.org/bot'.$config['api_key'].'/'.$endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $params,
    CURLOPT_TIMEOUT => 15,
  ]);
  $response = curl_exec($ch);
  if ($response === false) {
    fwrite(STDERR, 'curl error: '.curl_error($ch).PHP_EOL);
    exit(1);
  }
  curl_close($ch);
  echo $endpoint.PHP_EOL.$response.PHP_EOL;
