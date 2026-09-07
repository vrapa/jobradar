<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class TechnologyInput
{
    private const LEVELS = ['unknown', 'required', 'advantage'];

    public string $name;
    public string $normalizedName;

    public function __construct(
        string $name,
        public string $requirementLevel = 'unknown',
        public ?bool $provenExperience = null,
        public ?string $scoringRelevance = null,
        public ?string $evidence = null,
    ) {
        $this->name = trim($name);
        $this->normalizedName = mb_strtolower((string) preg_replace('/\s+/u', ' ', $this->name));
        if ($this->name === '' || mb_strlen($this->name) > 120) {
            throw new \InvalidArgumentException('Technologie musí mít název o délce 1 až 120 znaků.');
        }
        if (!in_array($this->requirementLevel, self::LEVELS, true)) {
            throw new \InvalidArgumentException('Neplatná úroveň požadavku technologie.');
        }
        if ($this->evidence !== null && mb_strlen($this->evidence) > 65_535) {
            throw new \InvalidArgumentException('Doklad technologie je příliš dlouhý.');
        }
    }
}
