<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentBreakdownView
{
    public function __construct(
        public string $area,
        public ?string $weight,
        public ?string $scoreMin,
        public ?string $scoreMax,
        public ?string $evidence,
        public string $explanation,
    ) {
    }
}
