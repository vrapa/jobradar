<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class OpportunityImport
{
    public function __construct(
        public string $url,
        public string $originalTitle,
        public string $originalText,
        public ?string $companyName = null,
        public ?string $translatedTitle = null,
        public ?string $translatedText = null,
        public ?string $summary = null,
        public ?string $sourceLanguage = null,
        public bool $incomplete = false,
    ) {
        if (trim($this->originalTitle) === '') {
            throw new \InvalidArgumentException('Původní titulek je povinný.');
        }
        if (trim($this->originalText) === '') {
            throw new \InvalidArgumentException('Původní text nabídky je povinný.');
        }
    }
}
