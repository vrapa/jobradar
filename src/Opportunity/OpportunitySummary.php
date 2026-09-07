<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Decision\OpportunityDecision;

final readonly class OpportunitySummary
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $companyName,
        public ?string $summary,
        public string $validityStatus,
        public bool $incomplete,
        public \DateTimeInterface $foundAt,
        public OpportunityDecision $decision,
        public string $workflowStatus,
    ) {
    }
}
