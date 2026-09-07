<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentFindingView
{
    public function __construct(
        public string $type,
        public string $severity,
        public string $text,
        public ?string $evidenceReference,
    ) {
    }
}
