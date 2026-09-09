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
        public ?ProjectCareInput $projectCare = null,
        public ?int $discoveryDefinitionId = null,
        public ?CounterpartyInput $counterparty = null,
    ) {
        if ($discoveryDefinitionId !== null && $discoveryDefinitionId < 1) { throw new \InvalidArgumentException('Neplatná definice nalezení.'); }
        if (trim($this->originalTitle) === '') {
            throw new \InvalidArgumentException('Původní titulek je povinný.');
        }
        if (trim($this->originalText) === '') {
            throw new \InvalidArgumentException('Původní text nabídky je povinný.');
        }
        $this->assertLength($this->originalTitle, 500, 'Původní titulek');
        $this->assertLength($this->translatedTitle, 500, 'Český titulek');
        $this->assertLength($this->companyName, 255, 'Název společnosti');
        $this->assertLength($this->sourceLanguage, 16, 'Jazyk originálu');
        $this->assertLength($this->summary, 65_535, 'Shrnutí');
    }

    private function assertLength(?string $value, int $maximum, string $label): void
    {
        if ($value !== null && mb_strlen(trim($value)) > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s smí mít nejvýše %d znaků.', $label, $maximum));
        }
    }
}
