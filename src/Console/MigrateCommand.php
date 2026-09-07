<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\MigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'database:migrate', description: 'Provede dosud neaplikované databázové migrace.')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly MigrationRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrations = $this->runner->migrate();
        if ($migrations === []) {
            $output->writeln('<info>Databáze je aktuální.</info>');
            return self::SUCCESS;
        }
        foreach ($migrations as $migration) {
            $output->writeln(sprintf('<info>Provedena migrace %s.</info>', $migration));
        }
        return self::SUCCESS;
    }
}
