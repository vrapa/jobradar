<?php

declare(strict_types=1);

namespace App\Api\Auth;

final class ApiRequestContext
{
    private ?ApiIdentity $identity = null;

    public function clear(): void
    {
        $this->identity = null;
    }

    public function authenticate(ApiIdentity $identity): void
    {
        $this->identity = $identity;
    }

    public function identity(): ApiIdentity
    {
        if ($this->identity === null) {
            throw new \LogicException('API požadavek nemá ověřenou identitu.');
        }
        return $this->identity;
    }
}
