<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SourceRunResult
{
    private const STATUSES = ['complete', 'partial', 'waiting_for_login', 'error', 'cancelled'];

    public function __construct(
        public string $status,
        public ?int $pagesTraversed = null,
        public ?int $displayedCount = null,
        public ?int $detailOpenedCount = null,
        public ?int $storedCount = null,
        public ?int $updatedCount = null,
        public ?int $duplicateCount = null,
        public ?int $rejectedCount = null,
        public ?string $incompleteReason = null,
        public ?string $errorCode = null,
    ) {
        if (!in_array($this->status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Neplatný výsledný stav zdroje.');
        }
        $counts = $this->counts();
        if (array_any($counts, static fn (?int $value): bool => $value !== null && $value < 0)) {
            throw new \InvalidArgumentException('Počty průchodu nesmějí být záporné.');
        }
        if ($this->status === 'complete' && array_any($counts, static fn (?int $value): bool => $value === null)) {
            throw new \InvalidArgumentException('Úplný průchod musí uvést počet stránek a všechny výsledné počty.');
        }
        if (in_array($this->status, ['partial', 'waiting_for_login', 'error'], true)
            && trim((string) $this->incompleteReason) === ''
        ) {
            throw new \InvalidArgumentException('Nedokončený zdroj musí uvést důvod.');
        }
        if ($this->status === 'error' && trim((string) $this->errorCode) === '') {
            throw new \InvalidArgumentException('Chyba zdroje musí mít bezpečný kód chyby.');
        }
    }

    /** @return list<int|null> */
    private function counts(): array
    {
        return [
            $this->pagesTraversed,
            $this->displayedCount,
            $this->detailOpenedCount,
            $this->storedCount,
            $this->updatedCount,
            $this->duplicateCount,
            $this->rejectedCount,
        ];
    }
}
