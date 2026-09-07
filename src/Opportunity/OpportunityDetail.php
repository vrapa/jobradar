<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class OpportunityDetail
{
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
    ) {
    }
}
