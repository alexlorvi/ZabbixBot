<?php

namespace ZabbixBot\Commands;

use \Telegram\Bot\Commands\Command as TgCommand;
use Telegram\Bot\Exceptions\TelegramOtherException;
use ZabbixBot\Services\LangService;

class StartCommand extends TgCommand {
    protected string $name = 'start';
    private LangService $msg;
    protected string $description;

    public function __construct() {
        $this->msg = LangService::getInstance();
        $this->description = $this->msg->getNested('command.'.$this->name.'.description');
    }

    public function handle()
    {
        $username = $this->getUpdate()->getMessage()->from->username;
        $userId = $this->getUpdate()->getMessage()->from->id;

        try {
            $this->replyWithMessage([
                'text' => sprintf($this->msg->getNested('command.'.$this->name.'.message'),$username,$userId),
                'parse_mode' => 'markdown',
            ]);
        } catch (TelegramOtherException $e) { 
            mainLOG('main','error',"Telegram Error: " . $e->getMessage()); 
        } catch (\Exception $e) { 
            mainLOG('main','error',"General Error: " . $e->getMessage()); 
        }
    }
}