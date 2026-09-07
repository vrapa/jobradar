<?php

declare(strict_types=1);

namespace App\Security;

use Nette\Database\Connection;

final class LoginRateLimiter
{
    private const MAXIMUM_FAILURES = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly Connection $database)
    {
    }

    public function assertAllowed(string $identifier, string $ipAddress): void
    {
        $failures = (int) $this->database->fetchField(
            'SELECT COUNT(*) FROM login_attempts
             WHERE successful = 0 AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
             AND (identifier_hash = ? OR ip_hash = ?)',
            self::WINDOW_MINUTES,
            $this->hash($identifier),
            $this->hash($ipAddress),
        );

        if ($failures >= self::MAXIMUM_FAILURES) {
            throw new LoginRateLimitException('Příliš mnoho neúspěšných pokusů. Zkuste to později.');
        }
    }

    public function record(string $identifier, string $ipAddress, bool $successful): void
    {
        $this->database->query(
            'INSERT INTO login_attempts',
            [
                'identifier_hash' => $this->hash($identifier),
                'ip_hash' => $this->hash($ipAddress),
                'successful' => $successful,
                'attempted_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
        );
    }

    private function hash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }
}
