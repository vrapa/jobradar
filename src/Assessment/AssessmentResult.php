<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentResult
{
    public function __construct(
        public int $assessmentId,
        public int $recommendationId,
        public int $opportunityLockVersion,
    ) {
    }
}
