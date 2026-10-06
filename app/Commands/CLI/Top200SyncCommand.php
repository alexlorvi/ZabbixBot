<?php

namespace ZabbixBot\Commands\CLI;

use ZabbixBot\Services\ZabbixService;
use ZabbixBot\Services\Top200Sync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Синхронізація групи TOP200 з роутерів WogRouters. Призначено для запуску з cron. */
class Top200SyncCommand extends Command
{
    protected static $defaultName = 'app:top200-sync';

    private ZabbixService $zbx;

    public function __construct(ZabbixService $zbx) {
        parent::__construct();
        $this->zbx = $zbx;
    }

    protected function configure()
    {
        $this->setDescription('Syncs the TOP200 Zabbix host group from WogRouters host inventory tags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('TOP200 Sync');

        $routersGroup = $this->zbx->getGroupIdByName('WogRouters');
        $topGroup = $this->zbx->getGroupIdByName('TOP200');
        if ($routersGroup === null || $topGroup === null) {
            mainLOG('main','error','top200: group WogRouters/TOP200 not found. Abort');
            $io->error('Group WogRouters/TOP200 not found in Zabbix.');
            return Command::FAILURE;
        }

        $routers = $this->zbx->getHostsByGroup($routersGroup);
        if (!$routers) {
            mainLOG('main','error','top200: WogRouters is empty. Abort, TOP200 untouched');
            $io->error('WogRouters group is empty. TOP200 left untouched.');
            return Command::FAILURE;
        }

        $currentTop = array_column((array)$this->zbx->getHostsByGroup($topGroup), 'hostid');
        $plan = Top200Sync::plan($routers, $currentTop);

        // Міняємо тільки різницю - група ніколи не буває порожньою посеред оновлення
        if ($plan['remove']) {
            $this->zbx->massRemoveHostGroup($topGroup, $plan['remove']);
        }
        if ($plan['add']) {
            $this->zbx->massAddHostGroup($topGroup, $plan['add']);
        }

        $summary = sprintf('top200 synced: +%d -%d (in scope %d)', count($plan['add']), count($plan['remove']), $plan['wanted']);
        mainLOG('main','info',$summary);
        $io->success($summary);
        return Command::SUCCESS;
    }
}
