<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class OpportunityTermsView
{
    public function __construct(
        public ?string $rateMin,
        public ?string $rateMax,
        public ?string $currency,
        public ?string $rateUnit,
        public ?string $engagementMode,
        public ?string $rateSource,
        public ?string $rateConfidence,
        public ?string $workloadMin,
        public ?string $workloadMax,
        public ?string $workloadUnit,
        public ?string $durationText,
        public ?string $remoteMode,
        public ?bool $workFromCzechia,
        public ?string $location,
        public ?string $workTimezone,
        public ?string $workingLanguage,
        public ?string $communicationMode,
        public ?\DateTimeInterface $verifiedAt,
    ) {
    }
}
