<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Nette\Database\Connection;

final class AuditLogger
{
    public function __construct(private readonly Connection $database)
    {
    }

    /** @param array<string, scalar|null> $context */
    public function record(string $event, ?int $actorUserId, array $context = []): void
    {
        $this->database->query(
            'INSERT INTO audit_log',
            [
                'event_type' => $event,
                'actor_user_id' => $actorUserId,
                'context_json' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
        );
    }
}
