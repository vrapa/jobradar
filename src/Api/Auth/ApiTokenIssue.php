<?php

declare(strict_types=1);

namespace App\Api\Auth;

final readonly class ApiTokenIssue
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $id,
        public string $token,
        public string $prefix,
        public array $scopes,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
