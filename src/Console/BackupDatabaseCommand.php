<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\DatabaseTransferService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'database:backup', description: 'Vytvoří konzistentní SQL zálohu bez vypsání databázového hesla.')]
final class BackupDatabaseCommand extends Command
{
    public function __construct(private readonly DatabaseTransferService $transfer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Nový cílový .sql soubor v existujícím adresáři.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $path = $this->transfer->backup((string) $input->getArgument('file'));
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::INVALID;
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
        $output->writeln('<info>Záloha byla bezpečně vytvořena.</info>');
        $output->writeln($path);
        return self::SUCCESS;
    }
}
