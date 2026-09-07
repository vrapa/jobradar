<?php

declare(strict_types=1);

namespace App\Api;

final readonly class AssistantActionReservation
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        public int $id,
        public bool $created,
        public string $status,
        public ?int $httpStatus = null,
        public ?array $response = null,
    ) {
    }
}
