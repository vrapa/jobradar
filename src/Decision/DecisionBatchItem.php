<?php

declare(strict_types=1);

namespace App\Decision;

final readonly class DecisionBatchItem
{
    public function __construct(
        public int $opportunityId,
        public int $expectedLockVersion,
        public OpportunityDecision $decision,
        public ?string $reason = null,
        public ?string $note = null,
    ) {
        if ($this->opportunityId < 1 || $this->expectedLockVersion < 0) {
            throw new \InvalidArgumentException('ID nabídky a očekávaná verze dávky nejsou platné.');
        }
    }
}
