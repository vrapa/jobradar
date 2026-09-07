<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentView
{
    /**
     * @param list<AssessmentBreakdownView> $breakdowns
     * @param list<AssessmentFindingView> $findings
     */
    public function __construct(
        public int $id,
        public AssessmentRecommendation $recommendation,
        public string $coverage,
        public string $confidence,
        public string $summary,
        public ?string $scoreMin,
        public ?string $scoreMax,
        public ?string $verifiedPoints,
        public string $profileName,
        public int $profileVersion,
        public string $ruleSetName,
        public int $ruleSetVersion,
        public string $ruleSetStatus,
        public string $authorType,
        public ?string $modelIdentifier,
        public \DateTimeInterface $createdAt,
        public array $breakdowns,
        public array $findings,
    ) {
    }
}
