<?php

declare(strict_types=1);

namespace App\Api\Auth;

use Tomaj\NetteApi\Authorization\ApiAuthorizationInterface;

final class ScopedBearerAuthorization implements ApiAuthorizationInterface
{
    private ?string $errorMessage = null;

    public function __construct(
        private readonly ApiCredentialService $credentials,
        private readonly ApiRequestContext $requestContext,
        private readonly string $requiredScope,
    ) {
    }

    public function authorized(): bool
    {
        $this->errorMessage = null;
        $this->requestContext->clear();
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || preg_match('/^Bearer ([A-Za-z0-9_-]+)$/D', $header, $matches) !== 1) {
            $this->errorMessage = 'Chybí platná Bearer autorizace.';
            return false;
        }
        try {
            $identity = $this->credentials->authenticate($matches[1], $this->requiredScope);
        } catch (ApiAuthenticationException) {
            $this->errorMessage = 'API token není platný nebo nemá požadované oprávnění.';
            return false;
        }
        $this->requestContext->authenticate($identity);
        return true;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
