<?php

namespace ZabbixBot;

use ZabbixBot\Services\ZabbixService;
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
            userLOG($this->userID,'error','Call displayUserEventsFull, but User ID not defined.');
            exit;
        }
        $events = $this->getUserEvents($severity,$group,$untilTime);
        if (count($events)>0) {
            $this->messenger->chatActionTyping($this->userID);
            $blocks = array_map(fn($e) => $this->formatEvent($e), $events);
            $this->messenger->sendBlocks($this->userID,$blocks,$this->ticketSeparator());
            $format =$this->msg->getNested('user.UserEventsFull.Count');
            $this->messenger->sendMessage($this->userID,sprintf($format,count($events)));
        } else {
            $this->messenger->sendMessage($this->userID,$this->msg->getNested('user.UserEventsFull.None'));
        }
    }

    public function displayUserEventsSummary($severity=[5],$group=NULL) {
        if (!isset($this->userID)) {
            userLOG($this->userID,'error','Call displayUserEventsSummary, but User ID not defined.');
            exit;
        }
        $events = $this->getUserEvents($severity,$group);

        if (count($events)>0) {
            $this->messenger->chatActionTyping($this->userID);
            $format = $this->msg->getNested('user.UserEventsSummary.Line');
            $blocks = [];
            foreach($events as $event) {
                $blocks[] = sprintf($format,
                                $this->severityLabel($event['severity'], true),
                                date('d/m/Y H:i:s',$event['clock']),
                                $event['eventid'],
                                $event['hostName'],
                                $event['hostHost'],
                                $event['name']);
            }
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
            $this->messenger->sendMessage($this->userID,$this->formatEvent($this->normalizeEvent($eventInfo, $eventInfo)));
        }
    }

    /** Роздільник між блоками подій у загальному переліку. */
    private function ticketSeparator(): string {
        return PHP_EOL.emoji('preatyline').PHP_EOL;
    }

    /** Подія в єдиному вигляді з problem.get (може бути порожнім) і event.get (хости, квитування, теги). */
    private function normalizeEvent(array $problem, array $info): array {
        return [
            'eventid' => (string)($problem['eventid'] ?? $info['eventid'] ?? ''),
            'name' => (string)($problem['name'] ?? $info['name'] ?? ''),
            'clock' => (int)($problem['clock'] ?? $info['clock'] ?? 0),
            'severity' => (int)($problem['severity'] ?? $info['severity'] ?? 0),
            'hostName' => (string)($info['hosts'][0]['name'] ?? ''),
            'hostHost' => (string)($info['hosts'][0]['host'] ?? ''),
            'acknowledged' => !empty($problem['acknowledged'] ?? $info['acknowledged'] ?? 0),
            'acknowledges' => is_array($info['acknowledges'] ?? null) ? $info['acknowledges'] : [],
            'tags' => is_array($info['tags'] ?? null) ? $info['tags'] : [],
        ];
    }

    /** Емодзі + назва рівня критичності (або лише емодзі). */
    private function severityLabel(int $severity, bool $emojiOnly = false): string {
        $label = (string)$this->msg->getNested('user.severity.'.$severity, (string)$severity);
        if ($emojiOnly) {
            $space = mb_strpos($label, ' ');
            return $space === false ? $label : mb_substr($label, 0, $space);
        }
        return $label;
    }

    /** "2д 3г" / "3г 15хв" / "15хв" з кількості секунд (одиниці - з i18n main.durUnits). */
    private function formatDuration(int $seconds): string {
        $u = $this->msg->getNested('main.durUnits', ['d' => 'd', 'h' => 'h', 'm' => 'm']);
        $seconds = max(0, $seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) return $d.$u['d'].' '.$h.$u['h'];
        if ($h > 0) return $h.$u['h'].' '.$m.$u['m'];
        return $m.$u['m'];
    }

    /** Докладний блок однієї події: критичність, час і тривалість, хост, назва, теги, квитування. */
    private function formatEvent(array $event): string {
        $reply = sprintf($this->msg->getNested('user.UserEventsFull.Line'),
            $this->severityLabel($event['severity']),
            date('d/m/Y H:i:s',$event['clock']),
            $this->formatDuration(time() - $event['clock']),
            $event['hostName'] !== '' ? $event['hostName'] : '-',
            $event['hostHost'] !== '' ? $event['hostHost'] : '-',
            $event['eventid'],
            $event['name'],
            $event['acknowledged'] ? unichr(0x2705) : '');

        $tags = [];
        foreach ($event['tags'] as $tag) {
            $tags[] = $tag['tag'].(($tag['value'] ?? '') !== '' ? ':'.$tag['value'] : '');
        }
        if ($tags) {
            $reply .= sprintf($this->msg->getNested('user.UserEventsFull.tagsLine'), implode(', ', $tags));
        }
        $format = $this->msg->getNested('user.UserEventsFull.ackLine');
        foreach($event['acknowledges'] as $acknowledge) {
            $reply .= sprintf($format,
                      date('d/m/Y H:i:s',$acknowledge['clock']),
                      $acknowledge['message'],
                      $acknowledge['author'] ?? $acknowledge['username'] ?? $acknowledge['userid'] ?? '');
        }
        return $reply;
    }

    /**
     * Відкриті проблеми користувача, збагачені деталями подій (один batch event.get замість запиту на кожну).
     * $group - назва або ID групи хостів.
     * @return list<array<string,mixed>>
     */
    private function getUserEvents($severity=[5],$group=NULL,$untilTime=NULL): array {
        $userToken = $this->getUserToken();
        if (!isset($userToken)) {
            exit;
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
            $responce[] = $this->normalizeEvent($problem, $infos[$problem['eventid']] ?? []);
        }
        return $responce;
    }

    public function isUser(){
        return $this->isZabbixUser;
    }

}