<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentInput
{
    public string $summary;

    public function __construct(
        public int $candidateProfileId,
        public int $scoringRuleSetId,
        public AssessmentRecommendation $recommendation,
        public string $coverage,
        public string $confidence,
        string $summary,
        public ?string $scoreMin = null,
        public ?string $scoreMax = null,
        public ?string $verifiedPoints = null,
        public string $authorType = 'assistant',
        public ?int $authorUserId = null,
        public ?string $modelIdentifier = null,
    ) {
        $this->summary = trim($summary);
        if ($this->candidateProfileId < 1 || $this->scoringRuleSetId < 1) {
            throw new \InvalidArgumentException('Posouzení musí odkazovat na profil a pravidla.');
        }
        self::assertRatio($this->coverage, 'Pokrytí');
        self::assertRatio($this->confidence, 'Jistota');
        self::assertScore($this->scoreMin, 'Minimální skóre');
        self::assertScore($this->scoreMax, 'Maximální skóre');
        self::assertScore($this->verifiedPoints, 'Ověřené body');
        if (($this->scoreMin === null) !== ($this->scoreMax === null)) {
            throw new \InvalidArgumentException('Rozsah skóre musí mít uvedenou obě hranice, nebo žádnou.');
        }
        if ($this->scoreMin !== null && (float) $this->scoreMin > (float) $this->scoreMax) {
            throw new \InvalidArgumentException('Minimální skóre nesmí být vyšší než maximální.');
        }
        if ($this->summary === '') {
            throw new \InvalidArgumentException('Shrnutí posouzení je povinné.');
        }
        if (!in_array($this->authorType, ['user', 'assistant', 'system'], true)) {
            throw new \InvalidArgumentException('Neplatný typ autora posouzení.');
        }
        if ($this->authorType === 'user' && $this->authorUserId === null) {
            throw new \InvalidArgumentException('Uživatelské posouzení musí mít autora.');
        }
        if ($this->authorType === 'assistant' && trim((string) $this->modelIdentifier) === '') {
            throw new \InvalidArgumentException('Posouzení asistenta musí uvést model.');
        }
    }

    public function hasNumericScore(): bool
    {
        return $this->scoreMin !== null || $this->scoreMax !== null || $this->verifiedPoints !== null;
    }

    private static function assertRatio(string $value, string $label): void
    {
        if (preg_match('/^(?:0(?:\.\d{1,3})?|1(?:\.0{1,3})?)$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' musí být číslo od 0 do 1.');
        }
    }

    private static function assertScore(?string $value, string $label): void
    {
        if ($value !== null && preg_match('/^-?\d{1,5}(?:\.\d{1,3})?$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' musí být číslo s nejvýše třemi desetinnými místy.');
        }
    }
}
