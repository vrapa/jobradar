<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SourceRunScope
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public string $description,
        public array $filters = [],
        public ?\DateTimeImmutable $horizonFrom = null,
        public ?\DateTimeImmutable $horizonTo = null,
    ) {
        if (trim($this->description) === '') {
            throw new \InvalidArgumentException('Skutečný rozsah průchodu zdroje musí mít popis.');
        }
        if ($this->horizonFrom !== null && $this->horizonTo !== null && $this->horizonFrom > $this->horizonTo) {
            throw new \InvalidArgumentException('Začátek časového horizontu nesmí být po jeho konci.');
        }
    }
}
