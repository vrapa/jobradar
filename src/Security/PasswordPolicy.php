<?php

declare(strict_types=1);

namespace App\Security;

final class PasswordPolicy
{
    public const MINIMUM_LENGTH = 14;

    public function validate(string $password): void
    {
        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Heslo musí mít alespoň %d znaků.', self::MINIMUM_LENGTH));
        }
        if (strlen($password) > 4096) {
            throw new \InvalidArgumentException('Heslo je příliš dlouhé.');
        }
    }
}
