<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Runner\FakeSourceAdapter;
use App\Runner\RunnerApiClientInterface;
use App\Runner\RunnerApiLease;
use App\Runner\RunnerSource;
use App\Runner\RunnerWorker;
use App\Search\SourceRunResult;
use App\Search\SourceRunScope;
use PHPUnit\Framework\TestCase;

final class RunnerWorkerTest extends TestCase
{
    public function testCompletesFakeSourceThroughVersionedApiOnly(): void
    {
        $api = new RecordingRunnerApiClient(
            [new RunnerSource(7, 'Fake source', 'https://fake.example.test', 'fake', 'unknown', false)],
            new RunnerApiLease(3, 5, str_repeat('a', 64), new \DateTimeImmutable('+5 minutes'), [7]),
        );
        $result = (new RunnerWorker($api, [new FakeSourceAdapter()]))->workOnce();

        self::assertSame('completed', $result->status);
        self::assertSame(3, $result->requestId);
        self::assertSame(1, $result->processedSources);
        self::assertSame(['start:5:7', 'finish:5:7:complete'], $api->events);
        self::assertSame(1, $api->finished[0]->pagesTraversed);
        self::assertSame(0, $api->finished[0]->storedCount);
    }

    public function testTruthfullyRecordsMissingAdapterWithoutClaimingTraversal(): void
    {
        $api = new RecordingRunnerApiClient(
            [new RunnerSource(8, 'Browser source', 'https://browser.example.test', 'browser', 'unknown', false)],
            new RunnerApiLease(4, 6, str_repeat('b', 64), new \DateTimeImmutable('+5 minutes'), [8]),
        );
        $result = (new RunnerWorker($api, [new FakeSourceAdapter()]))->workOnce();

        self::assertSame('partial', $result->status);
        self::assertSame('error', $api->finished[0]->status);
        self::assertSame('adapter_unavailable', $api->finished[0]->errorCode);
        self::assertNull($api->finished[0]->pagesTraversed);
    }

    public function testReturnsIdleWithoutProgressEvents(): void
    {
        $api = new RecordingRunnerApiClient([], null);
        $result = (new RunnerWorker($api, [new FakeSourceAdapter()]))->workOnce();

        self::assertSame('idle', $result->status);
        self::assertSame([], $api->events);
    }
}

final class RecordingRunnerApiClient implements RunnerApiClientInterface
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<SourceRunResult> */
    public array $finished = [];

    /**
     * @param list<RunnerSource> $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly ?RunnerApiLease $lease,
    ) {
    }

    public function listSources(): array
    {
        return $this->sources;
    }

    public function claimLease(): ?RunnerApiLease
    {
        return $this->lease;
    }

    public function startSource(int $runId, int $sourceId, string $leaseToken, SourceRunScope $scope): void
    {
        if (trim($scope->description) === '') {
            throw new \LogicException('Testovací klient dostal prázdný rozsah.');
        }
        $this->events[] = sprintf('start:%d:%d', $runId, $sourceId);
    }

    public function finishSource(int $runId, int $sourceId, string $leaseToken, SourceRunResult $result): void
    {
        $this->events[] = sprintf('finish:%d:%d:%s', $runId, $sourceId, $result->status);
        $this->finished[] = $result;
    }
}
