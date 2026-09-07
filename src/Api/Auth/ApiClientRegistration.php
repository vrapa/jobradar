<?php

declare(strict_types=1);

namespace App\Api\Auth;

final readonly class ApiClientRegistration
{
    public function __construct(
        public int $id,
        public string $publicIdentifier,
    ) {
    }
}
