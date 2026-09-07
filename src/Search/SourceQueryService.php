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

    public function getRequestDetail(int $userId, int $requestId): ?SearchRequestDetail
    {
        $request = $this->database->fetch(
            'SELECT request.id, request.request_status, request.requested_at, request.completed_at, request.cancelled_at,
                    run.id AS run_id, run.run_status, run.started_at, run.finished_at, run.completion_reason,
                    runner.name AS runner_name
             FROM search_requests request
             LEFT JOIN search_runs run ON run.search_request_id = request.id
             LEFT JOIN runner_devices runner ON runner.id = run.runner_device_id
             WHERE request.id = ? AND request.requested_by_user_id = ?',
            $requestId,
            $userId,
        );
        if (!$request instanceof Row) {
            return null;
        }

        $sources = array_map(
            static fn (Row $row): SearchRunSourceView => new SearchRunSourceView(
                sourceId: (int) $row['source_id'],
                sourceName: (string) $row['source_name'],
                priority: (string) $row['priority'],
                status: (string) ($row['source_status'] ?? 'planned'),
                queryText: self::nullableString($row['query_text']),
                horizonFrom: self::nullableDateTime($row['horizon_from']),
                horizonTo: self::nullableDateTime($row['horizon_to']),
                pagesTraversed: self::nullableInt($row['pages_traversed']),
                displayedCount: self::nullableInt($row['displayed_count']),
                detailOpenedCount: self::nullableInt($row['detail_opened_count']),
                storedCount: self::nullableInt($row['stored_count']),
                updatedCount: self::nullableInt($row['updated_count']),
                duplicateCount: self::nullableInt($row['duplicate_count']),
                rejectedCount: self::nullableInt($row['rejected_count']),
                startedAt: self::nullableDateTime($row['source_started_at']),
                finishedAt: self::nullableDateTime($row['source_finished_at']),
                incompleteReason: self::nullableString($row['incomplete_reason']),
                errorCode: self::nullableString($row['error_code']),
                loginRequired: (bool) ($row['login_required'] ?? false),
                loginUrl: self::nullableString($row['login_url']),
            ),
            $this->database->fetchAll(
                'SELECT request_source.source_id, source.name AS source_name, source.priority,
                        run_source.source_status, run_source.query_text, run_source.horizon_from, run_source.horizon_to,
                        run_source.pages_traversed, run_source.displayed_count, run_source.detail_opened_count,
                        run_source.stored_count, run_source.updated_count, run_source.duplicate_count,
                        run_source.rejected_count, run_source.started_at AS source_started_at,
                        run_source.finished_at AS source_finished_at, run_source.incomplete_reason,
                        run_source.error_code, run_source.login_required, access.login_url
                 FROM search_request_sources request_source
                 INNER JOIN sources source ON source.id = request_source.source_id
                 LEFT JOIN search_runs run ON run.search_request_id = request_source.search_request_id
                 LEFT JOIN search_run_sources run_source
                    ON run_source.search_run_id = run.id AND run_source.source_id = request_source.source_id
                 LEFT JOIN source_access_states access ON access.source_id = source.id
                 WHERE request_source.search_request_id = ?
                 ORDER BY source.priority, source.name',
                $requestId,
            ),
        );

        return new SearchRequestDetail(
            id: (int) $request['id'],
            status: (string) $request['request_status'],
            requestedAt: self::dateTime($request['requested_at']),
            completedAt: self::nullableDateTime($request['completed_at']),
            cancelledAt: self::nullableDateTime($request['cancelled_at']),
            runId: self::nullableInt($request['run_id']),
            runStatus: self::nullableString($request['run_status']),
            startedAt: self::nullableDateTime($request['started_at']),
            finishedAt: self::nullableDateTime($request['finished_at']),
            completionReason: self::nullableString($request['completion_reason']),
            runnerName: self::nullableString($request['runner_name']),
            sources: $sources,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
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
