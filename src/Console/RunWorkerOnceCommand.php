<?php

declare(strict_types=1);

namespace App\Console;

use App\Runner\FakeSourceAdapter;
use App\Runner\JobRadarRunnerApiClient;
use App\Runner\RunnerHttpClientInterface;
use App\Runner\RunnerWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runner:work-once', description: 'Převezme nejvýše jeden výslovně vyžádaný běh přes verzované API.')]
final class RunWorkerOnceCommand extends Command
{
    public function __construct(
        private readonly RunnerHttpClientInterface $http,
        private readonly FakeSourceAdapter $fakeAdapter,
        private readonly string $apiUrl,
        private readonly string $token,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (trim($this->token) === '') {
            $output->writeln('<error>Nastavte JOBRADAR_RUNNER_TOKEN; token se nepředává argumentem příkazu.</error>');
            return self::INVALID;
        }
        try {
            $worker = new RunnerWorker(
                new JobRadarRunnerApiClient($this->apiUrl, $this->token, $this->http),
                [$this->fakeAdapter],
            );
            $result = $worker->workOnce();
        } catch (\Throwable $exception) {
            $output->writeln('<error>Runner selhal: ' . self::safeMessage($exception) . '</error>');
            return self::FAILURE;
        }
        if ($result->status === 'idle') {
            $output->writeln('<info>Fronta neobsahuje žádný výslovně vyžádaný běh.</info>');
            return self::SUCCESS;
        }
        $output->writeln(sprintf(
            '<info>Požadavek %d: stav %s, zpracované zdroje %d.</info>',
            $result->requestId,
            $result->status,
            $result->processedSources,
        ));
        return $result->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private static function safeMessage(\Throwable $exception): string
    {
        return $exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException
            ? $exception->getMessage()
            : 'neočekávaná interní chyba';
    }
}
