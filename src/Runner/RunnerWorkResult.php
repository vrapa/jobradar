<?php

declare(strict_types=1);

namespace App\Runner;

final readonly class RunnerWorkResult
{
    public function __construct(
        public string $status,
        public ?int $requestId,
        public int $processedSources,
    ) {
    }
}
