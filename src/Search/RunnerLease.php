<?php

declare(strict_types=1);

namespace App\Search;

final readonly class RunnerLease
{
    /** @param list<int> $sourceIds */
    public function __construct(
        public int $requestId,
        public int $runId,
        public string $token,
        public \DateTimeImmutable $expiresAt,
        public array $sourceIds,
    ) {
    }
}
