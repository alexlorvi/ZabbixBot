<?php

namespace ZabbixBot\Commands\CLI;

use ZabbixBot\Services\MessageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

class SendMessagesCommand extends Command
{
    protected static $defaultName = 'app:send-message';

    private $messageService;

    public function __construct(MessageService $messageService) {
        parent::__construct();
        $this->messageService = $messageService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Sends an ad-hoc message to a Telegram chat')
            ->addArgument(
                'chatId',
                InputArgument::REQUIRED,
                'Telegram chat ID to send the message to'
            )
            ->addArgument(
                'message',
                InputArgument::REQUIRED,
                'Message text to send'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sending Message');

        $chatId = $input->getArgument('chatId');
        $message = $input->getArgument('message');

        $this->messageService->sendMessage($chatId, $message);

        $io->success("Message sent to chat $chatId.");
        return Command::SUCCESS;
    }
}
