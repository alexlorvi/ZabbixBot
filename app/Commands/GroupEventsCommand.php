<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\UserController;

class GroupEventsCommand extends Command {
    protected string $name = 'events';
    protected LangService $msg;
    protected ConfigService $config;
    protected string $pattern = '{groupName} {replyType}';

    protected UserController $user;
    protected MessageService $messenger;

    public function __construct() {
        $this->config = ConfigService::getInstance();
        $this->getCommandAliases();
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle()
    {
        $this->messenger = new MessageService($this->getTelegram());
        $userId = $this->getUpdate()->getMessage()->from->id;
        $this->user = new UserController($this->messenger,$userId);
        $text = $this->getUpdate()->getMessage()->getText();

        $groupName = $this->argument('groupName',$this->getGroupFromConfig($text));
        $replyType = $this->argument('replyType',$this->getReplyFromText($text));

        if (!$groupName) {
            try { 
                $reply = $this->msg->getNested('command.'.$this->name.'.usage').$this->listAliases();
                $message = $this->replyWithMessage([
                    'text' => $reply,
                    'parse_mode' => 'markdown',
                ]);
                userLOG($message->getChat()->getId(),'info','< Command Events Usage reply');
            } catch (TelegramOtherException $e) { 
                mainLOG('main','error',"Telegram Error: " . $e->getMessage()); 
            } catch (\Exception $e) { 
                mainLOG('main','error',"General Error: " . $e->getMessage()); 
            }
        } else {
            if ($replyType=='full') {
                $this->user->displayUserEventsFull(null,$groupName);
            } else {
                $this->user->displayUserEventsSummary(null,$groupName);
            }
        }
    }

    private function getReplyFromText($text) {
        return (stripos('full',$text)>0) ? 'full' : 'list';
    }

    private function getGroupFromConfig($text){
        $groups = $this->config->getNested('zabbix.groups');
        if (is_array($groups)) {
            foreach($groups as $group) {
                if (in_array(substr($text,1),$group['aliases'])) {
                    return $group['name'];
                }
            }
        }
        return null;
    }

    private function listAliases():string {
        $result ='';
        if (is_array($this->aliases)) {
            foreach($this->aliases as $alias) {
                $result .= '/'.$alias.PHP_EOL;
            }
        }
        return $result;
    }

    private function getCommandAliases(){
        $groups = $this->config->getNested('zabbix.groups');
        if (is_array($groups)) {
            foreach($groups as $group) {
                $this->aliases = array_merge($this->aliases,$group['aliases']);
            }
        }
    }
}