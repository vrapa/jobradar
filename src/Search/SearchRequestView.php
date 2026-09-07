<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchRequestView
{
    public function __construct(
        public int $id,
        public string $status,
        public int $sourceCount,
        public \DateTimeInterface $requestedAt,
        public ?\DateTimeInterface $completedAt,
    ) {
    }
}
