<?php

declare(strict_types=1);

namespace App\Runner;

final readonly class RunnerApiLease
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
