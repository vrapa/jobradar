<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class OpportunityTermsInput
{
    public function __construct(
        public ?string $rateMin = null,
        public ?string $rateMax = null,
        public ?string $currency = null,
        public ?string $rateUnit = null,
        public ?string $engagementMode = null,
        public ?string $rateSource = null,
        public ?string $rateConfidence = null,
        public ?string $workloadMin = null,
        public ?string $workloadMax = null,
        public ?string $workloadUnit = null,
        public ?string $durationText = null,
        public ?string $remoteMode = null,
        public ?bool $workFromCzechia = null,
        public ?string $location = null,
        public ?string $workTimezone = null,
        public ?string $workingLanguage = null,
        public ?string $communicationMode = null,
    ) {
        $this->assertDecimal($this->rateMin, 'Minimální sazba');
        $this->assertDecimal($this->rateMax, 'Maximální sazba');
        $this->assertDecimal($this->workloadMin, 'Minimální rozsah');
        $this->assertDecimal($this->workloadMax, 'Maximální rozsah');
        $this->assertConfidence($this->rateConfidence);
        if ($this->rateMin !== null && $this->rateMax !== null && (float) $this->rateMin > (float) $this->rateMax) {
            throw new \InvalidArgumentException('Minimální sazba nesmí být vyšší než maximální.');
        }
        if ($this->workloadMin !== null && $this->workloadMax !== null && (float) $this->workloadMin > (float) $this->workloadMax) {
            throw new \InvalidArgumentException('Minimální rozsah nesmí být vyšší než maximální.');
        }
        if ($this->currency !== null && preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            throw new \InvalidArgumentException('Měna musí být třípísmenný ISO kód, například CZK nebo EUR.');
        }
        if ($this->hasKnownRate() && (trim((string) $this->rateSource) === '' || $this->rateConfidence === null)) {
            throw new \InvalidArgumentException('U známé sazby uveďte její původ a míru jistoty.');
        }
        if (!$this->hasKnownRate() && ($this->rateSource !== null || $this->rateConfidence !== null)) {
            throw new \InvalidArgumentException('Původ a jistotu sazby lze uložit jen společně se sazbou.');
        }
    }

    public function hasKnownRate(): bool
    {
        return $this->rateMin !== null || $this->rateMax !== null;
    }

    private function assertDecimal(?string $value, string $label): void
    {
        if ($value !== null && preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' musí být nezáporné číslo s nejvýše dvěma desetinnými místy.');
        }
    }

    private function assertConfidence(?string $value): void
    {
        if ($value !== null && (preg_match('/^(?:0(?:\.\d{1,3})?|1(?:\.0{1,3})?)$/', $value) !== 1)) {
            throw new \InvalidArgumentException('Jistota sazby musí být číslo od 0 do 1 s nejvýše třemi desetinnými místy.');
        }
    }
}
