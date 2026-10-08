<?php

namespace ZabbixBot\Commands\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ZabbixBot\Services\EventFormatter;
use ZabbixBot\Services\FileCache;
use ZabbixBot\Services\LangService;
use ZabbixBot\Services\MessageService;
use ZabbixBot\Services\PingService;
use ZabbixBot\Services\UphostJobs;

/**
 * Фонове очікування хоста для /uphost (запускає UphostCommand через setsid/nohup): ping кожні --interval с,
 * доки хост не відповість або не спливе час. Результат - відповіддю на команду; "чекаю" прибирається.
 */
class UphostJobCommand extends Command
{
    protected static $defaultName = 'app:uphost-job';

    public function __construct(private readonly MessageService $messenger) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Internal: wait until a host answers ping (/uphost)')
            ->setHidden(true)
            ->addArgument('chatId', InputArgument::REQUIRED)
            ->addArgument('host', InputArgument::REQUIRED)
            ->addArgument('minutes', InputArgument::REQUIRED)
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between checks', '15')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Language of the reply')
            ->addOption('reply-to', null, InputOption::VALUE_REQUIRED, 'Message id to reply to (the /uphost command)')
            ->addOption('notice', null, InputOption::VALUE_REQUIRED, 'Message id of the "waiting" notice to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $chatId = (string)$input->getArgument('chatId');
        $host = (string)$input->getArgument('host');
        $minutes = max(1, (int)$input->getArgument('minutes'));
        $interval = max(5, (int)$input->getOption('interval'));
        $msg = LangService::getInstance();
        if ($input->getOption('lang') !== null) {
            $msg->setLang($input->getOption('lang'));
        }
        $cache = new FileCache(CACHE_PATH);
        $start = time();
        $deadline = $start + $minutes * 60;
        userLOG($chatId, 'info', "Uphost job started: $host, $minutes min (pid ".getmypid().')');
        try {
            $up = false;
            while (true) {
                if (PingService::alive($host)) {
                    $up = true;
                    break;
                }
                if (time() + $interval > $deadline) {
                    break;
                }
                sleep($interval);
            }

            $options = ['keep_keyboard' => true];
            $replyTo = (int)$input->getOption('reply-to');
            if ($replyTo > 0) {
                $options['reply_parameters'] = json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true]);
            }
            if ($up) {
                $text = sprintf($msg->getNested('command.uphost.up'), $host,
                    EventFormatter::duration(time() - $start, (array)$msg->getNested('main.durUnits')));
                $button = ['text' => "\u{1F3D3} Ping", 'callback_data' => 'net:ping:'.$host];
            } else {
                $text = sprintf($msg->getNested('command.uphost.timeout'), $host, $minutes);
                $button = ['text' => $msg->getNested('command.uphost.waitMore'), 'callback_data' => 'up:'.$host];
            }
            if (strlen($button['callback_data']) <= 64) {
                $options['reply_markup'] = json_encode(['inline_keyboard' => [[$button]]], JSON_UNESCAPED_UNICODE);
            }
            $this->messenger->sendMessage($chatId, $text, $options);
            if ((int)$input->getOption('notice') > 0) {
                $this->messenger->deleteMessage($chatId, (int)$input->getOption('notice'));
            }
            userLOG($chatId, 'info', "Uphost job done: $host ".($up ? 'up' : 'timeout'));
        } finally {
            UphostJobs::remove($cache, $chatId, UphostJobs::key($host));
        }
        return Command::SUCCESS;
    }
}
