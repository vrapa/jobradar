<?php

declare(strict_types=1);

namespace App\Search;

use Nette\Database\Connection;
use Nette\Database\Row;

final class SourceQueryService
{
    public function __construct(private readonly Connection $database)
    {
    }

    /** @return list<SourceView> */
    public function activeCheckableSources(): array
    {
        return array_map(
            static fn (Row $row): SourceView => new SourceView(
                id: (int) $row['id'],
                name: (string) $row['name'],
                url: (string) $row['url'],
                marketCode: self::nullableString($row['market_code']),
                type: (string) $row['source_type'],
                priority: (string) $row['priority'],
                recommendedFrequencyHours: $row['recommended_frequency_hours'] === null ? null : (int) $row['recommended_frequency_hours'],
                accessStatus: (string) ($row['access_status'] ?? 'unknown'),
                accessVerifiedAt: self::nullableDateTime($row['verified_at']),
                interventionRequired: (bool) ($row['intervention_required'] ?? false),
                loginUrl: self::nullableString($row['login_url']),
            ),
            $this->database->fetchAll(
                'SELECT s.*, access.access_status, access.verified_at, access.intervention_required, access.login_url
                 FROM sources s LEFT JOIN source_access_states access ON access.source_id = s.id
                 WHERE s.active = 1 AND s.archived_at IS NULL AND s.source_type <> ?
                 ORDER BY s.priority, s.name',
                'manual',
            ),
        );
    }

    /** @return list<SearchRequestView> */
    public function recentRequests(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return array_map(
            static fn (Row $row): SearchRequestView => new SearchRequestView(
                id: (int) $row['id'],
                status: (string) $row['request_status'],
                sourceCount: (int) $row['source_count'],
                requestedAt: self::dateTime($row['requested_at']),
                completedAt: self::nullableDateTime($row['completed_at']),
            ),
            $this->database->fetchAll(
                'SELECT request.id, request.request_status, request.requested_at, request.completed_at,
                        COUNT(request_source.source_id) AS source_count
                 FROM search_requests request
                 INNER JOIN search_request_sources request_source ON request_source.search_request_id = request.id
                 WHERE request.requested_by_user_id = ?
                 GROUP BY request.id, request.request_status, request.requested_at, request.completed_at
                 ORDER BY request.requested_at DESC, request.id DESC LIMIT ?',
                $userId,
                $limit,
            ),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function dateTime(mixed $value): \DateTimeInterface
    {
        if (!$value instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('Databáze vrátila neplatné datum kontroly.');
        }
        return $value;
    }

    private static function nullableDateTime(mixed $value): ?\DateTimeInterface
    {
        return $value === null ? null : self::dateTime($value);
    }
}
