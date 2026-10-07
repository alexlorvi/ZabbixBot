<?php

/**
 * Точка прийому сповіщень з Zabbix (webhook-медіатип, див. docs/zabbix-alerts.md).
 * POST JSON, заголовок X-Alert-Token = config alerts.token.
 */

require_once __DIR__.'/config/constants.php';
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/tools/helpers.php';

use ZabbixBot\Models\User;
use ZabbixBot\Services\AckService;
use ZabbixBot\Services\AlertFormatter;
use ZabbixBot\Services\AlertService;
use ZabbixBot\Services\AlertStore;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\TelegramFactory;
use ZabbixBot\Services\ZabbixService;

function alertReply(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = ConfigService::getInstance();
$token = (string)$cfg->getNested('alerts.token', '');
if ($token === '') {
    alertReply(503, ['ok' => false, 'error' => 'alerts disabled: alerts.token is not configured']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    alertReply(405, ['ok' => false, 'error' => 'POST only']);
}
if (!hash_equals($token, (string)($_SERVER['HTTP_X_ALERT_TOKEN'] ?? ''))) {
    mainLOG('main', 'warning', 'Alert token mismatch from '.($_SERVER['REMOTE_ADDR'] ?? '?'));
    alertReply(401, ['ok' => false, 'error' => 'unauthorized']);
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

/** Тексти мовою отримувача (його налаштування /settings, інакше telegram.lang). */
function langFor(string $chatId): LangService {
    $lang = LangService::getInstance();
    $lang->setLang((new User($chatId))->get('lang') ?? ConfigService::getInstance()->getNested('telegram.lang'));
    return $lang;
}

$zabbix = new ZabbixService();
$messenger = new MessageService(TelegramFactory::make((array)$cfg->getNested('telegram')));
$service = new AlertService(
    new AlertStore(ALERT_PATH, (int)$cfg->getNested('alerts.ttl_days', 30)),
    fn(string $chatId, string $text, array $options): ?int => $messenger->sendMessage($chatId, $text, $options),
    fn(string $chatId): bool => $zabbix->isUser($chatId),
    (bool)$cfg->getNested('alerts.require_known_user', true),
    // Кнопки "Квитувати"/"Коментар" під проблемою, мовою користувача (його налаштування /settings)
    (bool)$cfg->getNested('alerts.ack_buttons', true)
        ? function (string $chatId, string $eventId): string {
            $lang = langFor($chatId);
            return AckService::keyboard($eventId, ['ack' => $lang->getNested('ack.button'), 'comment' => $lang->getNested('ack.commentButton')]);
        }
        : null,
    // Текст сповіщення формує бот мовою отримувача з полів медіатипу (app:mediatype); без них - subject/message Zabbix
    function (string $chatId, array $payload, string $mode): ?string {
        $lang = langFor($chatId);
        return AlertFormatter::render($payload, $mode, (array)$lang->getNested('alert', []) + [
            'severity' => (array)$lang->getNested('user.severity', []),
            'units' => (array)$lang->getNested('main.durUnits', ['d' => 'd', 'h' => 'h', 'm' => 'm']),
        ]);
    },
);

$result = $service->handle($payload);
mainLOG('main', $result['ok'] ? 'info' : 'warning', 'Alert '.json_encode(['to' => $payload['sendto'] ?? null, 'event' => $payload['event_id'] ?? null] + $result));
$status = $result['status'];
unset($result['status']);
alertReply($status, $result);
