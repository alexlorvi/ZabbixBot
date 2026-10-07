<?php

namespace ZabbixBot;

use ZabbixBot\Services\ZabbixService;
use ZabbixBot\Services\AckService;
use ZabbixBot\Services\AlertStore;
use ZabbixBot\Services\EventFormatter;
use ZabbixBot\Services\CommandList;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\TokenStore;
use ZabbixBot\Services\UserTokens;
use ZabbixBot\Models\User;


class UserController {
    protected ZabbixService $zabbixService;
    protected LangService $msg;
    protected MessageService $messenger;
    protected UserTokens $userTokens;
    protected int $userID;
    protected bool $isZabbixUser = false;
    protected User $user;
    protected ?AlertStore $alertStore = null;

    public function __construct(MessageService $message, $userID = null) {
        $this->messenger = $message;
        $this->zabbixService = new ZabbixService();
        $this->msg = LangService::getInstance();

        $cfg = ConfigService::getInstance();
        $this->userTokens = new UserTokens(
            new TokenStore(TOKEN_PATH, $cfg->getNested('zabbix.token_key')),
            $this->zabbixService,
            (int)$cfg->getNested('zabbix.user_token_ttl_days', 90),
        );

        if (isset($userID)) $this->setUserID($userID);
    }

    public function setUserID($userID) {
        $this->userID = $userID;
        $this->isZabbixUser = $this->zabbixService->isUser($this->userID);
        $this->user = New User($this->userID);
        if ($this->isZabbixUser) $this->userPrepare();
    }

    private function userPrepare(){
        $userLang = $this->user->get('lang');
        if (!isset($userLang)) {
            $cfg = ConfigService::getInstance();
            $this->user->set('lang',$cfg->getNested('telegram.lang'));
            $this->user->writeUserPreference();
        } else {
            $this->msg->setLang($userLang);
        }
    }

    /** Zabbix userid, прив'язаний до поточного автентифікованого chat_id. */
    public function getZabbixUserId(): ?string {
        return $this->zabbixService->getUserID($this->userID);
    }

    /** Вже створений інстанс ZabbixService (щоб не плодити другий FileCache тощо). */
    public function zabbix(): ZabbixService {
        return $this->zabbixService;
    }

    public function getPreference(string $key, $default = null) {
        return $this->user->get($key, $default);
    }

    public function setPreference(string $key, $value): void {
        $this->user->set($key, $value);
        $this->user->writeUserPreference();
    }

    /** Персональний API-токен Zabbix користувача: зі сховища, або випускається/оновлюється на льоту. */
    public function getUserToken(): ?string {
        $zbxUserId = $this->zabbixService->getUserID($this->userID);
        if (!isset($zbxUserId)) {
            userLOG($this->userID,'error','Zabbix ID not found.');
            return null;
        }
        $token = $this->userTokens->tokenFor((string)$this->userID, ['userid' => $zbxUserId]);
        if ($token === null) {
            userLOG($this->userID,'error','Не вдалося отримати API-токен Zabbix.');
        }
        return $token;
    }

    public function displayUserEventsFull($severity=[5],$group=NULL,$untilTime=NULL) {
        if (!isset($this->userID)) {
            mainLOG('main','error','Call displayUserEventsFull, but User ID not defined.');
            return;
        }
        $events = $this->getUserEvents($severity,$group,$untilTime);
        if ($events === null) {
            return;
        }
        if (count($events)>0) {
            $this->messenger->chatActionTyping($this->userID);
            $i18n = $this->eventI18n();
            $blocks = array_map(fn($e) => EventFormatter::format($e, $i18n, time()), $events);
            $this->messenger->sendBlocks($this->userID,$blocks,$this->ticketSeparator());
            $format =$this->msg->getNested('user.UserEventsFull.Count');
            $this->messenger->sendMessage($this->userID,sprintf($format,count($events)));
        } else {
            $this->messenger->sendMessage($this->userID,$this->msg->getNested('user.UserEventsFull.None'));
        }
    }

    public function displayUserEventsSummary($severity=[5],$group=NULL) {
        if (!isset($this->userID)) {
            mainLOG('main','error','Call displayUserEventsSummary, but User ID not defined.');
            return;
        }
        $events = $this->getUserEvents($severity,$group);
        if ($events === null) {
            return;
        }

        if (count($events)>0) {
            $this->messenger->chatActionTyping($this->userID);
            $i18n = $this->eventI18n();
            $blocks = array_map(fn($e) => EventFormatter::summary($e, $i18n), $events);
            $this->messenger->sendBlocks($this->userID,$blocks,$this->ticketSeparator());
            $format =$this->msg->getNested('user.UserEventsSummary.Count');
            $this->messenger->sendMessage($this->userID,sprintf($format,count($events)));
        } else {
            $this->messenger->sendMessage($this->userID,$this->msg->getNested('user.UserEventsSummary.None'));
        }
    }

    public function displayEventById($eventID){
        $eventInfo = $this->zabbixService->getEventInfo($eventID);
        if (is_array($eventInfo)) {
            $event = EventFormatter::normalize($eventInfo, $eventInfo);
            $sentId = $this->messenger->sendMessage($this->userID, EventFormatter::format($event, $this->eventI18n(), time()), [
                'reply_markup' => AckService::keyboard((string)$eventID, $this->ackLabels(), !$event['acknowledged']),
            ]);
            if ($sentId !== null) {
                $this->alertStore()->indexMessage((string)$eventID, (string)$this->userID, $sentId);
            }
        }
    }

    private function alertStore(): AlertStore {
        return $this->alertStore ??= new AlertStore(ALERT_PATH, (int)ConfigService::getInstance()->getNested('alerts.ttl_days', 30));
    }

    /** @return array{ack:string,comment:string} */
    public function ackLabels(): array {
        return ['ack' => (string)$this->msg->getNested('ack.button'), 'comment' => (string)$this->msg->getNested('ack.commentButton')];
    }

    /** AckService з викликом Zabbix особистим токеном користувача; null - токена немає (користувачу вже повідомлено). */
    private function ackService(): ?AckService {
        $token = $this->getUserToken();
        if ($token === null) {
            $this->messenger->sendMessage($this->userID, $this->msg->getNested('user.tokenError'));
            return null;
        }
        return new AckService(
            fn(string $eventId, int $action, ?string $message): ?string => $this->zabbixService->acknowledgeEvent($token, $eventId, $action, $message));
    }

    /** Відповідь на повідомлення бота, щоб підтвердження було поруч зі сповіщенням. */
    private function replyOptions(?int $toMessageId, array $options = []): array {
        $options['keep_keyboard'] = true;
        if ($toMessageId !== null) {
            $options['reply_parameters'] = json_encode(['message_id' => $toMessageId, 'allow_sending_without_reply' => true]);
        }
        return $options;
    }

    /** Кнопка "Квитувати" (callback ack:<id>) під сповіщенням або /ev<id>. */
    public function acknowledgeEvent(string $eventId, ?int $fromMessageId = null): void {
        $ack = $this->ackService();
        if ($ack === null) {
            return;
        }
        $error = $ack->acknowledge($eventId);
        if ($error !== null) {
            userLOG($this->userID,'warning','Acknowledge /ev'.$eventId.' failed: '.$error);
            $this->messenger->sendMessage($this->userID, sprintf($this->msg->getNested('ack.error'), $error), $this->replyOptions($fromMessageId));
            return;
        }
        userLOG($this->userID,'info','Acknowledged /ev'.$eventId);
        if ($fromMessageId !== null) {
            $this->messenger->editMarkup($this->userID, $fromMessageId, AckService::keyboard($eventId, $this->ackLabels(), false));
        }
        $this->messenger->sendMessage($this->userID, sprintf($this->msg->getNested('ack.done'), $eventId), $this->replyOptions($fromMessageId));
    }

    /** Кнопка "Коментар" (callback ackmsg:<id>): просимо відповісти текстом на повідомлення з force_reply. */
    public function promptComment(string $eventId, ?int $fromMessageId = null): void {
        if (!ctype_digit($eventId)) {
            return;
        }
        $markup = json_encode(['force_reply' => true, 'input_field_placeholder' => mb_substr((string)$this->msg->getNested('ack.placeholder'), 0, 64)], JSON_UNESCAPED_UNICODE);
        $sentId = $this->messenger->sendMessage($this->userID, sprintf($this->msg->getNested('ack.prompt'), $eventId),
            $this->replyOptions($fromMessageId, ['reply_markup' => $markup]));
        if ($sentId !== null) {
            $this->alertStore()->indexMessage($eventId, (string)$this->userID, $sentId);
        }
    }

    /**
     * Текстова відповідь на повідомлення бота: якщо воно про одну подію - коментар у Zabbix.
     * @return bool false - повідомлення не стосується події (звичайний текст)
     */
    public function commentFromReply(int $replyToMessageId, string $replyToText, string $text, int $messageId): bool {
        $eventId = AckService::eventIdFor($this->alertStore(), (string)$this->userID, $replyToMessageId, $replyToText);
        if ($eventId === null) {
            return false;
        }
        $ack = $this->ackService();
        if ($ack === null) {
            return true;
        }
        $error = $ack->comment($eventId, $text);
        if ($error !== null) {
            userLOG($this->userID,'warning','Comment /ev'.$eventId.' failed: '.$error);
            $this->messenger->sendMessage($this->userID, sprintf($this->msg->getNested('ack.error'), $error), $this->replyOptions($messageId));
            return true;
        }
        userLOG($this->userID,'info','Commented /ev'.$eventId);
        $this->messenger->sendMessage($this->userID, sprintf($this->msg->getNested('ack.commented'), $eventId), $this->replyOptions($messageId));
        return true;
    }

    /** i18n-рядки форматування подій для EventFormatter. */
    private function eventI18n(): array {
        return [
            'severity' => (array)$this->msg->getNested('user.severity', []),
            'line' => $this->msg->getNested('user.UserEventsFull.Line'),
            'summaryLine' => $this->msg->getNested('user.UserEventsSummary.Line'),
            'tagsLine' => $this->msg->getNested('user.UserEventsFull.tagsLine'),
            'ackLine' => $this->msg->getNested('user.UserEventsFull.ackLine'),
            'units' => $this->msg->getNested('main.durUnits', ['d' => 'd', 'h' => 'h', 'm' => 'm']),
        ];
    }

    /** Роздільник між блоками подій у загальному переліку. */
    private function ticketSeparator(): string {
        return PHP_EOL.emoji('preatyline').PHP_EOL;
    }

    public function isAdmin(): bool {
        return $this->isZabbixUser && $this->zabbixService->isAdmin((string)$this->userID);
    }

    /** Скидає кеш користувачів/груп Zabbix; лише для адмінів. @return bool false, якщо не адмін */
    public function resetCache(): bool {
        if (!$this->isAdmin()) {
            return false;
        }
        $this->zabbixService->resetUserCache();
        return true;
    }

    /** Текст довідки зі списком команд; адмінські команди (telegram.admin_commands, за замовчуванням reset) - лише адмінам. */
    public function commandListText(array $commands): string {
        $descriptions = [];
        foreach ($commands as $name => $command) {
            $descriptions[$name] = $command->getDescription();
        }
        $adminOnly = (array)ConfigService::getInstance()->getNested('telegram.admin_commands', ['reset']);
        $isAdmin = $this->isAdmin();
        $text = CommandList::render($descriptions, $adminOnly, $isAdmin);
        return $isAdmin ? $this->msg->getNested('command.menu.admin_badge').PHP_EOL.$text : $text;
    }

    /**
     * Відкриті проблеми користувача, збагачені деталями подій (один batch event.get замість запиту на кожну).
     * $group - назва або ID групи хостів.
     * @return list<array<string,mixed>>|null null - не вдалося отримати токен (користувачу вже повідомлено)
     */
    private function getUserEvents($severity=[5],$group=NULL,$untilTime=NULL): ?array {
        $userToken = $this->getUserToken();
        if (!isset($userToken)) {
            $this->messenger->sendMessage($this->userID,$this->msg->getNested('user.tokenError'));
            return null;
        }
        $groupId = null;
        if ($group !== null) {
            $groupId = ctype_digit((string)$group) ? (string)$group : $this->zabbixService->getGroupIdByName((string)$group);
            if ($groupId === null) {
                userLOG($this->userID,'error','Zabbix group not found: '.$group);
                return [];
            }
        }
        $problems = $this->zabbixService->getUserProblems($userToken,$severity,$groupId,$untilTime);
        $infos = $this->zabbixService->getEventsInfo(array_column($problems, 'eventid'));
        $responce = [];
        foreach($problems as $problem) {
            $responce[] = EventFormatter::normalize($problem, $infos[$problem['eventid']] ?? []);
        }
        return $responce;
    }

    public function isUser(){
        return $this->isZabbixUser;
    }

}