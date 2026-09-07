<?php

declare(strict_types=1);

namespace App\Decision;

final readonly class DecisionBatchResult
{
    public function __construct(
        public int $delegationId,
        public int $processed,
        public int $changed,
    ) {
    }
}
