<?php

namespace ZabbixBot\Commands\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\PingService;
use ZabbixBot\Services\Router;

/**
 * Фоновий довгий пінг (понад PingService::LIVE_MAX пакетів), запускається PingCommand через nohup.
 * Шле лише підсумок (ping -q) відповіддю на команду, з кнопкою "Повторити"; прибирає повідомлення "взято в роботу".
 */
class PingJobCommand extends Command
{
    protected static $defaultName = 'app:ping-job';

    public function __construct(private readonly MessageService $messenger) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Internal: background ping for /ping with more than '.PingService::LIVE_MAX.' packets')
            ->setHidden(true)
            ->addArgument('chatId', InputArgument::REQUIRED)
            ->addArgument('host', InputArgument::REQUIRED)
            ->addArgument('count', InputArgument::REQUIRED)
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Language of the reply')
            ->addOption('reply-to', null, InputOption::VALUE_REQUIRED, 'Message id to reply to (the /ping command)')
            ->addOption('notice', null, InputOption::VALUE_REQUIRED, 'Message id of the "started" notice to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $chatId = (string)$input->getArgument('chatId');
        $host = (string)$input->getArgument('host');
        $count = (int)$input->getArgument('count');
        $msg = LangService::getInstance();
        if ($input->getOption('lang') !== null) {
            $msg->setLang($input->getOption('lang'));
        }
        $cache = new FileCache(CACHE_PATH);
        userLOG($chatId, 'info', "Background ping started: $host x$count (pid ".getmypid().')');
        try {
            $result = (new PingService())->summary($host, $count);
            $options = ['keep_keyboard' => true];
            $replyTo = (int)$input->getOption('reply-to');
            if ($replyTo > 0) {
                $options['reply_parameters'] = json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true]);
            }
            $markup = Router::repeatMarkup('ping', $host, $count, $msg->getNested('net.repeat'));
            if ($markup !== null) {
                $options['reply_markup'] = $markup;
            }
            $this->messenger->sendMessage($chatId,
                sprintf($msg->getNested('command.ping.bulkResult'), $host, $count, $result['ok'] ? "\u{2705}" : "\u{1F6AB}")."\n".$result['text'],
                $options);
            if ((int)$input->getOption('notice') > 0) {
                $this->messenger->deleteMessage($chatId, (int)$input->getOption('notice'));
            }
            userLOG($chatId, 'info', "Background ping done: $host x$count, ".($result['ok'] ? 'ok' : 'failed'));
        } finally {
            PingService::unlock($cache, $chatId);
        }
        return Command::SUCCESS;
    }
}
