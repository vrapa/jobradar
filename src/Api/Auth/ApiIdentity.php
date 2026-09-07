<?php

declare(strict_types=1);

namespace App\Api\Auth;

final readonly class ApiIdentity
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $tokenId,
        public int $clientId,
        public int $ownerUserId,
        public string $clientIdentifier,
        public string $clientName,
        public string $clientType,
        public array $scopes,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
