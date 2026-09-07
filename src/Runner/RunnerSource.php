<?php

declare(strict_types=1);

namespace App\Runner;

final readonly class RunnerSource
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public string $type,
        public string $accessStatus,
        public bool $interventionRequired,
    ) {
    }
}
