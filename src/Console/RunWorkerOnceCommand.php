<?php

declare(strict_types=1);

namespace App\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runner:work-once', description: 'Vypnutý demonstrační worker; použijte vykonávací MCP.')]
final class RunWorkerOnceCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>Ukázkový worker je vypnutý. Kontroly přebírá Codex přes vykonávací MCP.</error>');
        return self::INVALID;
    }
}
