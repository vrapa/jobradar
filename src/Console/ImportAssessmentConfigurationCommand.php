<?php

declare(strict_types=1);

namespace App\Console;

use App\Assessment\AssessmentConfigurationMapper;
use App\Assessment\AssessmentConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'assessment:import-config', description: 'Vytvoří verzovaný profil a návrh pravidel z JSON souboru.')]
final class ImportAssessmentConfigurationCommand extends Command
{
    public function __construct(
        private readonly AssessmentConfigurationMapper $mapper,
        private readonly AssessmentConfigurationService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Cesta k JSON konfiguraci.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pouze ověří konfiguraci.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('file');
        if (!is_file($path) || !is_readable($path) || filesize($path) > 1_048_576) {
            $output->writeln('<error>Konfigurace neexistuje, nelze ji přečíst nebo přesahuje 1 MiB.</error>');
            return self::FAILURE;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            $output->writeln('<error>Konfiguraci se nepodařilo načíst.</error>');
            return self::FAILURE;
        }
        try {
            $configuration = $this->mapper->map($json);
            if ($input->getOption('dry-run')) {
                $output->writeln('<info>Konfigurace je platná. Nic nebylo zapsáno a pravidla nebyla aktivována.</info>');
                return self::SUCCESS;
            }
            $result = $this->service->createDraft($configuration);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');
            return self::INVALID;
        }
        $output->writeln(sprintf(
            '<info>Vytvořen profil #%d a návrh pravidel #%d. Pravidla nejsou aktivní.</info>',
            $result->profileId,
            $result->ruleSetId,
        ));
        return self::SUCCESS;
    }
}
