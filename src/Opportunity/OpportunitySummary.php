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
        public ?string $rateMin,
        public ?string $rateMax,
        public ?string $currency,
        public ?string $rateUnit,
        public ?string $workloadMin,
        public ?string $workloadMax,
        public ?string $workloadUnit,
        public ?string $scoreMin,
        public ?string $scoreMax,
        public ?int $coveragePercent,
        public ?string $recommendation,
        public ?bool $projectCare = null,
    ) {
    }
}
