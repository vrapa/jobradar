<?php

declare(strict_types=1);

namespace App\Console;

use App\Assessment\AssessmentPayloadMapper;
use App\Assessment\AssessmentService;
use App\Opportunity\OpportunityConflictException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'assessment:import', description: 'Uloží validované posouzení nabídky z JSON souboru.')]
final class ImportAssessmentCommand extends Command
{
    public function __construct(
        private readonly AssessmentPayloadMapper $mapper,
        private readonly AssessmentService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Cesta k JSON posouzení.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pouze ověří datový tvar a hodnoty.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('file');
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size > 1_048_576 || !is_readable($path)) {
            $output->writeln('<error>Posouzení neexistuje, nelze je přečíst nebo přesahuje 1 MiB.</error>');
            return self::FAILURE;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            $output->writeln('<error>Posouzení se nepodařilo načíst.</error>');
            return self::FAILURE;
        }
        try {
            $payload = $this->mapper->map($json);
            if ($input->getOption('dry-run')) {
                $output->writeln('<info>Posouzení je platné. Nic nebylo zapsáno.</info>');
                return self::SUCCESS;
            }
            $result = $this->service->save(
                $payload->opportunityId,
                $payload->expectedLockVersion,
                $payload->assessment,
                $payload->breakdowns,
                $payload->findings,
            );
        } catch (OpportunityConflictException $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');
            return self::FAILURE;
        } catch (\InvalidArgumentException|\ValueError $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');
            return self::INVALID;
        }
        $output->writeln(sprintf(
            '<info>Uloženo posouzení #%d a doporučení #%d. Uživatelské rozhodnutí nebylo změněno.</info>',
            $result->assessmentId,
            $result->recommendationId,
        ));
        return self::SUCCESS;
    }
}
