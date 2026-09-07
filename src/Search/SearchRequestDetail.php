<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchRequestDetail
{
    /** @param list<SearchRunSourceView> $sources */
    public function __construct(
        public int $id,
        public string $status,
        public \DateTimeInterface $requestedAt,
        public ?\DateTimeInterface $completedAt,
        public ?\DateTimeInterface $cancelledAt,
        public ?int $runId,
        public ?string $runStatus,
        public ?\DateTimeInterface $startedAt,
        public ?\DateTimeInterface $finishedAt,
        public ?string $completionReason,
        public ?string $runnerName,
        public array $sources,
    ) {
    }

    public function canResume(): bool
    {
        return $this->status === 'waiting_for_login';
    }

    public function canCancel(): bool
    {
        return !in_array($this->status, ['complete', 'partial', 'cancelled', 'error'], true);
    }

    public function checkedSourceCount(): int
    {
        return count(array_filter(
            $this->sources,
            static fn (SearchRunSourceView $source): bool => $source->wasCheckedCompletely(),
        ));
    }
}
