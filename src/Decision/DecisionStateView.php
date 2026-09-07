<?php

declare(strict_types=1);

namespace App\Decision;

final readonly class DecisionStateView
{
    public function __construct(
        public OpportunityDecision $decision,
        public ?string $reason,
        public ?string $note,
        public string $workflowStatus,
        public int $lockVersion,
    ) {
    }
}
