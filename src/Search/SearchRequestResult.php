<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchRequestResult
{
    public function __construct(
        public int $requestId,
        public string $status,
        public bool $created,
    ) {
    }
}
