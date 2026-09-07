<?php

declare(strict_types=1);

namespace App\Decision;

final readonly class DecisionDelegationResult
{
    /** @param list<int> $opportunityIds */
    public function __construct(
        public int $id,
        public array $opportunityIds,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
