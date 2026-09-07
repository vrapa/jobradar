<?php

declare(strict_types=1);

namespace App\Decision;

final readonly class DecisionHistoryView
{
    public function __construct(
        public int $id,
        public OpportunityDecision $previousDecision,
        public OpportunityDecision $newDecision,
        public ?string $reason,
        public ?string $reasonLabel,
        public ?string $note,
        public string $actorType,
        public \DateTimeInterface $createdAt,
    ) {
    }
}
