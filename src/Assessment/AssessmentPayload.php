<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentPayload
{
    /**
     * @param list<AssessmentBreakdownInput> $breakdowns
     * @param list<AssessmentFindingInput> $findings
     */
    public function __construct(
        public int $opportunityId,
        public int $expectedLockVersion,
        public AssessmentInput $assessment,
        public array $breakdowns,
        public array $findings,
    ) {
        if ($this->opportunityId < 1 || $this->expectedLockVersion < 1) {
            throw new \InvalidArgumentException('ID nabídky a očekávaná verze musí být kladná čísla.');
        }
    }
}
