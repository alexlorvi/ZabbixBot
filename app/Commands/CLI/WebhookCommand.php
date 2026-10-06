<?php

namespace ZabbixBot\Commands\CLI;

use ZabbixBot\BotController;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Керування Telegram-вебхуком: set|info|del. Заміняє ручне розкоментування блоку в index.php. */
class WebhookCommand extends Command
{
    protected static $defaultName = 'app:webhook';

    protected function configure()
    {
        $this
            ->setDescription('Manage the Telegram webhook (set/info/del)')
            ->addArgument('action', InputArgument::REQUIRED, 'set|info|del');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        $app = new BotController();

        switch ($action) {
            case 'set':
                $io->writeln($app->registerHook());
                break;

            case 'info':
                $io->writeln(json_encode($app->getWebhookInfo(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                break;

            case 'del':
                $io->writeln($app->deleteWebhook());
                break;

            default:
                $io->error("Unknown action '$action'. Use: set|info|del");
                return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
