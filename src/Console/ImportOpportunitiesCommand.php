<?php

declare(strict_types=1);

namespace App\Console;

use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityJsonMapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'opportunity:import-json', description: 'Importuje jednu nebo více nabídek z JSON souboru.')]
final class ImportOpportunitiesCommand extends Command
{
    private const MAX_FILE_SIZE = 10_485_760;

    public function __construct(
        private readonly OpportunityJsonMapper $mapper,
        private readonly OpportunityImportService $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Cesta k JSON souboru.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pouze ověří vstup, nic nezapíše.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('file');
        if (!is_file($path) || !is_readable($path)) {
            $output->writeln('<error>JSON soubor neexistuje nebo jej nelze přečíst.</error>');
            return self::FAILURE;
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            $output->writeln('<error>JSON soubor smí mít nejvýše 10 MiB.</error>');
            return self::FAILURE;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            $output->writeln('<error>JSON soubor se nepodařilo načíst.</error>');
            return self::FAILURE;
        }

        try {
            $imports = $this->mapper->map($json);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');
            return self::INVALID;
        }
        if ($input->getOption('dry-run')) {
            $output->writeln(sprintf('<info>Vstup je platný. Nabídek: %d. Nic nebylo zapsáno.</info>', count($imports)));
            return self::SUCCESS;
        }

        $created = 0;
        $versions = 0;
        foreach ($imports as $import) {
            $result = $this->importer->import($import);
            $created += (int) $result->opportunityCreated;
            $versions += (int) $result->versionCreated;
        }
        $output->writeln(sprintf(
            '<info>Zpracováno %d nabídek; nových nabídek %d, nových verzí %d.</info>',
            count($imports),
            $created,
            $versions,
        ));

        return self::SUCCESS;
    }
}
