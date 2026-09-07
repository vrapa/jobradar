<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SourceView
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public ?string $marketCode,
        public string $type,
        public string $priority,
        public ?int $recommendedFrequencyHours,
        public string $accessStatus,
        public ?\DateTimeInterface $accessVerifiedAt,
        public bool $interventionRequired,
        public ?string $loginUrl,
    ) {
    }
}
