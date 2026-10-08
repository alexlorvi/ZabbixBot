<?php

namespace ZabbixBot\Commands\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ZabbixBot\Models\User;
use ZabbixBot\Services\AlertCleanup;
use ZabbixBot\Services\AlertStore;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\MessageService;

/**
 * Автовидалення пар "проблема + відновлення" (cron, напр. щогодини):
 *   php console.php app:alert-cleanup --hours=12
 * Видаляє сповіщення подій, відновлених щонайменше --hours годин тому (1..47: Telegram дозволяє видаляти
 * повідомлення лише до 48 год). Користувач вимикає це в /settings (preference alert_autodelete).
 */
class AlertCleanupCommand extends Command
{
    protected static $defaultName = 'app:alert-cleanup';

    public function __construct(private readonly MessageService $messenger) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Delete Zabbix alert messages (problem + recovery) some hours after recovery')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED,
                'Minimum hours since recovery ('.AlertCleanup::MIN_HOURS.'-'.AlertCleanup::MAX_HOURS.')', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = (int)$input->getOption('hours');
        if ((string)$hours !== (string)$input->getOption('hours') || $hours < AlertCleanup::MIN_HOURS || $hours > AlertCleanup::MAX_HOURS) {
            $output->writeln('<error>--hours must be an integer '.AlertCleanup::MIN_HOURS.'-'.AlertCleanup::MAX_HOURS
                .' (Telegram lets bots delete messages only within 48 hours)</error>');
            return Command::INVALID;
        }
        $store = new AlertStore(ALERT_PATH, (int)ConfigService::getInstance()->getNested('alerts.ttl_days', 30));
        $stats = AlertCleanup::run($store, time(), $hours,
            fn(string $chatId): bool => self::enabledFor($chatId),
            fn(string $chatId, int $messageId): bool => $this->messenger->deleteMessage($chatId, $messageId),
        );
        $line = sprintf('events %d, deleted %d, failed %d, too old %d, autodelete off %d',
            $stats['events'], $stats['deleted'], $stats['failed'], $stats['tooOld'], $stats['skipped']);
        mainLOG('main', 'info', 'Alert cleanup (--hours='.$hours.'): '.$line);
        $output->writeln($line);
        return Command::SUCCESS;
    }

    /** Налаштування користувача: за замовчуванням автовидалення ввімкнене. */
    public static function enabledFor(string $chatId): bool
    {
        return (new User($chatId))->get('alert_autodelete', '1') !== '0';
    }
}
