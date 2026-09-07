<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchRunSourceView
{
    public function __construct(
        public int $sourceId,
        public string $sourceName,
        public string $priority,
        public string $status,
        public ?string $queryText,
        public ?\DateTimeInterface $horizonFrom,
        public ?\DateTimeInterface $horizonTo,
        public ?int $pagesTraversed,
        public ?int $displayedCount,
        public ?int $detailOpenedCount,
        public ?int $storedCount,
        public ?int $updatedCount,
        public ?int $duplicateCount,
        public ?int $rejectedCount,
        public ?\DateTimeInterface $startedAt,
        public ?\DateTimeInterface $finishedAt,
        public ?string $incompleteReason,
        public ?string $errorCode,
        public bool $loginRequired,
        public ?string $loginUrl,
    ) {
    }

    public function wasStarted(): bool
    {
        return $this->startedAt !== null;
    }

    public function wasCheckedCompletely(): bool
    {
        return $this->status === 'complete';
    }
}
