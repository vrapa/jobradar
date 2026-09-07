<?php

declare(strict_types=1);

namespace App\Runner;

use App\Search\SourceRunResult;
use App\Search\SourceRunScope;

interface RunnerApiClientInterface
{
    /** @return list<RunnerSource> */
    public function listSources(): array;

    public function claimLease(): ?RunnerApiLease;

    public function startSource(int $runId, int $sourceId, string $leaseToken, SourceRunScope $scope): void;

    public function finishSource(int $runId, int $sourceId, string $leaseToken, SourceRunResult $result): void;
}
