<?php

declare(strict_types=1);

namespace App\Decision;

use Nette\Database\Connection;
use Nette\Database\Row;

final class DecisionQueryService
{
    private const REASON_LABELS = [
        'low_rate' => 'Nízká sazba',
        'workload' => 'Nevhodný rozsah',
        'not_remote' => 'Nelze remote z ČR',
        'language_communication' => 'Jazyk nebo komunikace',
        'technology' => 'Nevhodné technologie',
        'wordpress_small_web' => 'WordPress nebo malý web',
        'expired' => 'Neaktuální nabídka',
        'duplicate' => 'Duplicita',
        'other' => 'Jiný důvod',
    ];

    public function __construct(private readonly Connection $database)
    {
    }

    /** @return list<DecisionHistoryView> */
    public function history(int $userId, int $opportunityId): array
    {
        return array_map(
            static function (Row $row): DecisionHistoryView {
                $reason = self::nullable($row['reason']);
                return new DecisionHistoryView(
                    id: (int) $row['id'],
                    previousDecision: OpportunityDecision::from((string) $row['previous_decision']),
                    newDecision: OpportunityDecision::from((string) $row['new_decision']),
                    reason: $reason,
                    reasonLabel: $reason === null ? null : (self::REASON_LABELS[$reason] ?? $reason),
                    note: self::nullable($row['note']),
                    actorType: (string) $row['actor_type'],
                    createdAt: self::dateTime($row['created_at']),
                );
            },
            $this->database->fetchAll(
                'SELECT id, previous_decision, new_decision, reason, note, actor_type, created_at
                 FROM opportunity_decision_history
                 WHERE user_id = ? AND opportunity_id = ?
                 ORDER BY created_at DESC, id DESC',
                $userId,
                $opportunityId,
            ),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function dateTime(mixed $value): \DateTimeInterface
    {
        if (!$value instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný čas rozhodnutí.');
        }
        return $value;
    }
}
