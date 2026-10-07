<?php

namespace ZabbixBot\Commands\CLI;

use ZabbixBot\Services\MessageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

class RetryMessagesCommand extends Command
{
    protected static $defaultName = 'app:retry-messages';

    private $messageService;

    public function __construct(MessageService $messageService) {
        parent::__construct();
        $this->messageService = $messageService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Retries sending queued messages')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit the number of messages to retry',
                10 // Default limit
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Retrying Queued Messages');

        $limit = $input->getOption('limit');

        $delivered = $this->messageService->retryMessages((int)$limit);
        $left = $this->messageService->getMessageQueueSize();

        if ($left > 0 && $delivered < (int)$limit) {
            $io->warning("$delivered delivered, $left still queued (Telegram unreachable or retry stopped; see logs).");
        } else {
            $io->success("$delivered messages delivered, $left left in queue.");
        }
        return Command::SUCCESS;
    }
}
