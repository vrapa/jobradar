<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\DatabaseTransferService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'database:restore', description: 'Obnoví SQL zálohu výhradně do prázdné databáze.')]
final class RestoreDatabaseCommand extends Command
{
    public function __construct(private readonly DatabaseTransferService $transfer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Existující čitelný .sql soubor zálohy.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->transfer->restore((string) $input->getArgument('file'));
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::INVALID;
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('<info>Databáze byla obnovena ze zálohy.</info>');
        return self::SUCCESS;
    }
}
