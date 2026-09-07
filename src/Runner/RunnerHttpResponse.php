<?php

declare(strict_types=1);

namespace App\Runner;

final readonly class RunnerHttpResponse
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $statusCode,
        public array $payload,
    ) {
    }
}
