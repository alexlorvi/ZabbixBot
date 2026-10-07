<?php

namespace ZabbixBot\Commands\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZabbixBot\Services\ConfigService;
use ZabbixBot\Services\MediaTypeDefinition;
use ZabbixBot\Services\ZabbixService;

/**
 * Webhook-медіатип Zabbix для alert.php: export - YAML для імпорту в Zabbix, install - створити НОВИЙ медіатип через
 * API для подальшого ручного доналаштування (наявні медіатипи не змінюються). Мова шаблонів - лише з --lang.
 * Визначення - MediaTypeDefinition, скрипт - docs/zabbix-mediatype.js.
 */
class MediaTypeCommand extends Command
{
    protected static $defaultName = 'app:mediatype';

    private const URL_PLACEHOLDER = 'https://<bot-host>/alert.php';
    private const TOKEN_PLACEHOLDER = '<alerts.token>';

    public function __construct(private readonly ZabbixService $zbx) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Export or install the Zabbix webhook media type for alert.php')
            ->addArgument('action', InputArgument::REQUIRED, 'export|install')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'alert.php URL (default: alerts.url, else derived from telegram.webhook_url)')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Required. Language of the message templates and ack buttons: '.implode('|', MediaTypeDefinition::languages()))
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Media type name (must not exist yet)', MediaTypeDefinition::DEFAULT_NAME)
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'HTTP proxy Zabbix should use to reach alert.php', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'install: show what would be done, change nothing')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'export: write to file instead of stdout')
            ->addOption('placeholders', null, InputOption::VALUE_NONE, 'export: put placeholders instead of the real url/token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cfg = ConfigService::getInstance();

        $lang = (string)$input->getOption('lang');
        if (!in_array($lang, MediaTypeDefinition::languages(), true)) {
            $io->error(($lang === '' ? 'Pass --lang' : "Unknown language '$lang'").'. Use: --lang='.implode('|', MediaTypeDefinition::languages()));
            return Command::FAILURE;
        }
        $url = (string)($input->getOption('url') ?? $cfg->getNested('alerts.url') ?? self::deriveUrl((string)$cfg->getNested('telegram.webhook_url', '')));
        $token = (string)$cfg->getNested('alerts.token', '');

        switch ($input->getArgument('action')) {
            case 'export':
                return $this->export($input, $io, $lang, $url, $token);
            case 'install':
                return $this->install($input, $io, $lang, $url, $token);
            default:
                $io->error("Unknown action '".$input->getArgument('action')."'. Use: export|install");
                return Command::FAILURE;
        }
    }

    /** https://host/bot/index.php -> https://host/bot/alert.php; https://host/bot/ -> https://host/bot/alert.php */
    public static function deriveUrl(string $webhookUrl): string
    {
        if ($webhookUrl === '') {
            return '';
        }
        $base = preg_match('#\.php$#', $webhookUrl) ? substr($webhookUrl, 0, strrpos($webhookUrl, '/')) : rtrim($webhookUrl, '/');
        return $base.'/alert.php';
    }

    private function export(InputInterface $input, SymfonyStyle $io, string $lang, string $url, string $token): int
    {
        $placeholders = $input->getOption('placeholders') || $url === '' || $token === '';
        if ($placeholders && !$input->getOption('placeholders')) {
            $io->getErrorStyle()->warning('alerts.url/alerts.token are not configured - exporting placeholders, fill url/token after import.');
        }
        $def = new MediaTypeDefinition(
            $placeholders ? self::URL_PLACEHOLDER : $url,
            $placeholders ? self::TOKEN_PLACEHOLDER : $token,
            $lang,
            (string)$input->getOption('name'),
            (string)$input->getOption('proxy'),
        );
        $yaml = $def->exportYaml();

        $file = $input->getOption('output');
        if ($file === null) {
            $io->write($yaml, false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }
        if (file_put_contents($file, $yaml) === false) {
            $io->error("Cannot write $file");
            return Command::FAILURE;
        }
        if (!$placeholders) {
            chmod($file, 0600);
            $io->getErrorStyle()->warning("$file contains alerts.token - do not commit or share it.");
        }
        $io->getErrorStyle()->success("Exported to $file. Import: Alerts -> Media types -> Import.");
        return Command::SUCCESS;
    }

    private function install(InputInterface $input, SymfonyStyle $io, string $lang, string $url, string $token): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $problems = [];
        if ($token === '') {
            $problems[] = 'alerts.token is empty: alert.php is disabled. Set it in config/config.php first.';
        }
        if (!preg_match('#^https?://#', $url)) {
            $problems[] = 'No alert.php URL: set alerts.url in config/config.php or pass --url.';
        }
        if ($problems && !$dryRun) {
            $io->error($problems);
            return Command::FAILURE;
        }
        if ($problems) {
            $io->warning($problems);
        }

        $name = (string)$input->getOption('name');
        $existing = $this->zbx->findMediaTypeByName($name);
        if ($existing !== null) {
            $io->error("Media type '$name' already exists (#{$existing['mediatypeid']}). It is not changed - pass another --name to create a new one.");
            return Command::FAILURE;
        }

        $def = new MediaTypeDefinition($url, $token, $lang, $name, (string)$input->getOption('proxy'));
        $io->definitionList(
            ['Action' => "create '$name'"],
            ['alert.php' => $url],
            ['Language' => $lang],
        );
        if ($dryRun) {
            $io->success('Dry run: nothing changed.');
            return Command::SUCCESS;
        }

        $mediaTypeId = $this->zbx->createMediaType($def->apiFields());
        if ($mediaTypeId === null) {
            $io->error('mediatype.create failed: '.$this->zbx->lastError());
            return Command::FAILURE;
        }
        mainLOG('main', 'info', "app:mediatype install: created media type #$mediaTypeId '$name' -> $url ($lang)");
        $io->success("Media type #$mediaTypeId '$name' created.");
        $io->text([
            'Next, in Zabbix (Alerts -> Media types):',
            ' - review/adjust the message templates and parameters;',
            ' - add this media type to users (Send to = Telegram chat id);',
            ' - use it in Actions: problem, recovery and update operations.',
            'The bot recognises its users by zabbix.mediatype_id ('.ConfigService::getInstance()->getNested('zabbix.mediatype_id', '16').') - change it in config.php if users will move to this media type.',
        ]);
        return Command::SUCCESS;
    }
}
