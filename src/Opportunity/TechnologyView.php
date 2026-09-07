<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class TechnologyView
{
    public function __construct(
        public string $name,
        public string $requirementLevel,
        public ?bool $provenExperience,
        public ?string $scoringRelevance,
        public ?string $evidence,
    ) {
    }
}
