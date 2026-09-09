<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Decision\DecisionStateView;

final readonly class OpportunityDetail
{
    /**
     * @param list<OpportunityVersion> $versions
     * @param list<TechnologyView> $technologies
     * @param list<array<string, mixed>> $projectCareHistory
     * @param list<array<string, mixed>> $discoveries
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $originalTitle,
        public ?string $translatedTitle,
        public string $originalText,
        public ?string $translatedText,
        public ?string $summary,
        public ?string $companyName,
        public string $canonicalUrl,
        public string $validityStatus,
        public ?string $sourceLanguage,
        public bool $incomplete,
        public \DateTimeInterface $foundAt,
        public \DateTimeInterface $acquiredAt,
        public int $versionCount,
        public array $versions,
        public int $lockVersion,
        public ?OpportunityTermsView $terms,
        public array $technologies,
        public DecisionStateView $decisionState,
        public array $projectCareHistory = [],
        public array $discoveries = [],
    ) {
    }
}
