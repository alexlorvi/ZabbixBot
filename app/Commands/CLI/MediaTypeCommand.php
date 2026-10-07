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
 * Webhook-медіатип Zabbix для alert.php: export - YAML для імпорту в Zabbix, install - створити/оновити через API.
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
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Language of the fallback Zabbix templates: '.implode('|', MediaTypeDefinition::languages()).' (default: telegram.lang)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Media type name (default: '.MediaTypeDefinition::DEFAULT_NAME.'; with --mediatype-id the current name is kept)')
            ->addOption('mediatype-id', null, InputOption::VALUE_REQUIRED, 'install: update this existing media type (e.g. the current Telegram one, users keep their media)')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'HTTP proxy Zabbix should use to reach alert.php', '')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'install: show what would be done, change nothing')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'install: do not ask before updating an existing media type')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'export: write to file instead of stdout')
            ->addOption('placeholders', null, InputOption::VALUE_NONE, 'export: put placeholders instead of the real url/token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cfg = ConfigService::getInstance();

        $lang = (string)($input->getOption('lang') ?? $cfg->getNested('telegram.lang', 'ua'));
        if (!in_array($lang, MediaTypeDefinition::languages(), true)) {
            $io->error("Unknown language '$lang'. Use: ".implode('|', MediaTypeDefinition::languages()));
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
            (string)($input->getOption('name') ?? MediaTypeDefinition::DEFAULT_NAME),
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

        $id = $input->getOption('mediatype-id');
        $nameOpt = $input->getOption('name');
        $name = (string)($nameOpt ?? MediaTypeDefinition::DEFAULT_NAME);
        $existing = $id !== null ? $this->zbx->findMediaType((string)$id, null) : $this->zbx->findMediaType(null, $name);
        if ($id !== null && $existing === null) {
            $io->error("Media type #$id not found".($this->zbx->lastError() ? ': '.$this->zbx->lastError() : '.'));
            return Command::FAILURE;
        }
        if ($existing !== null && (string)$existing['type'] !== '4') {
            $io->error("Media type #{$existing['mediatypeid']} '{$existing['name']}' is not a webhook - refusing to change its type.");
            return Command::FAILURE;
        }

        $def = new MediaTypeDefinition($url, $token, $lang, $existing !== null && $nameOpt === null ? (string)$existing['name'] : $name, (string)$input->getOption('proxy'));
        $io->definitionList(
            ['Action' => $existing !== null ? "update #{$existing['mediatypeid']} '{$existing['name']}'" : "create '$name'"],
            ['alert.php' => $url],
            ['Fallback templates' => $lang],
            ['Parameters' => count($def->parameters())],
        );

        if ($existing !== null) {
            $users = $this->zbx->countMediaTypeUsers((string)$existing['mediatypeid']);
            $io->note(sprintf('%s user(s) have this media type: after the update their notifications go through alert.php (Send to must be the Telegram chat id).', $users ?? '?'));
        }
        if ($dryRun) {
            $io->success('Dry run: nothing changed.');
            return Command::SUCCESS;
        }
        if ($existing !== null && !$input->getOption('yes') && !$io->confirm('Update this media type?', false)) {
            $io->warning('Cancelled.');
            return Command::FAILURE;
        }

        if ($existing !== null) {
            $mediaTypeId = (string)$existing['mediatypeid'];
            if (!$this->zbx->updateMediaType($mediaTypeId, $def->apiFields($nameOpt !== null))) {
                $io->error('mediatype.update failed: '.$this->zbx->lastError());
                return Command::FAILURE;
            }
        } else {
            $mediaTypeId = $this->zbx->createMediaType($def->apiFields());
            if ($mediaTypeId === null) {
                $io->error('mediatype.create failed: '.$this->zbx->lastError());
                return Command::FAILURE;
            }
        }
        mainLOG('main', 'info', "app:mediatype install: media type #$mediaTypeId -> $url ($lang)");
        $io->success("Media type #$mediaTypeId is ready.");

        $configured = (string)ConfigService::getInstance()->getNested('zabbix.mediatype_id', '16');
        if ($configured !== $mediaTypeId) {
            $io->warning([
                "The bot recognises users by media type zabbix.mediatype_id = $configured, this one is #$mediaTypeId.",
                "Either set 'mediatype_id' => '$mediaTypeId' in the zabbix section of config/config.php (users then need media of this type),",
                "or install over the current one: php console.php app:mediatype install --mediatype-id=$configured",
            ]);
        }
        $io->text('Next: in Zabbix Actions use this media type in problem, recovery and update operations.');
        return Command::SUCCESS;
    }
}
