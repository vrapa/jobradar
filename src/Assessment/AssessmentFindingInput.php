<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentFindingInput
{
    private const TYPES = ['strong_match', 'acceptable_gap', 'blocker', 'question'];
    private const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

    public string $text;

    public function __construct(
        public string $type,
        public string $severity,
        string $text,
        public ?string $evidenceReference = null,
    ) {
        $this->text = trim($text);
        if (!in_array($this->type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ nálezu.');
        }
        if (!in_array($this->severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException('Neplatná závažnost nálezu.');
        }
        if ($this->text === '') {
            throw new \InvalidArgumentException('Text nálezu je povinný.');
        }
        if ($this->evidenceReference !== null && mb_strlen($this->evidenceReference) > 500) {
            throw new \InvalidArgumentException('Odkaz na podklad smí mít nejvýše 500 znaků.');
        }
    }
}
