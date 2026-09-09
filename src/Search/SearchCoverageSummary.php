<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchCoverageSummary
{
    public function __construct(
        public int $requestId,
        public string $status,
        public int $plannedCount,
        public int $completeCount,
        public int $loginRequiredCount,
        public int $errorCount,
        public \DateTimeInterface $requestedAt,
        public int $partialCount = 0,
        public int $pendingCount = 0,
        public int $unverifiedCount = 0,
    ) {
    }
}
