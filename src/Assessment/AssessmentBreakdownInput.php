<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentBreakdownInput
{
    public string $area;
    public string $explanation;

    public function __construct(
        string $area,
        string $explanation,
        public ?string $weight = null,
        public ?string $scoreMin = null,
        public ?string $scoreMax = null,
        public ?string $evidence = null,
    ) {
        $this->area = trim($area);
        $this->explanation = trim($explanation);
        if ($this->area === '' || mb_strlen($this->area) > 100) {
            throw new \InvalidArgumentException('Oblast rozkladu musí mít 1 až 100 znaků.');
        }
        if ($this->explanation === '') {
            throw new \InvalidArgumentException('Vysvětlení oblasti je povinné.');
        }
        self::assertDecimal($this->weight, 'Váha');
        self::assertDecimal($this->scoreMin, 'Minimální body');
        self::assertDecimal($this->scoreMax, 'Maximální body');
        if ($this->scoreMin !== null && $this->scoreMax !== null && (float) $this->scoreMin > (float) $this->scoreMax) {
            throw new \InvalidArgumentException('Minimální body oblasti nesmějí být vyšší než maximální.');
        }
    }

    private static function assertDecimal(?string $value, string $label): void
    {
        if ($value !== null && preg_match('/^-?\d{1,5}(?:\.\d{1,3})?$/', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' musí být číslo s nejvýše třemi desetinnými místy.');
        }
    }
}
