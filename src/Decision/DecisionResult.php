<?php

declare(strict_types=1);

namespace App\Decision;

final readonly class DecisionResult
{
    public function __construct(
        public OpportunityDecision $previousDecision,
        public OpportunityDecision $decision,
        public int $lockVersion,
        public bool $changed,
    ) {
    }
}
