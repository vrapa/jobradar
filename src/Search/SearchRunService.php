<?php

declare(strict_types=1);

namespace App\Search;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class SearchRunService
{
    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function startSource(int $requestId, string $leaseToken, int $sourceId, SourceRunScope $scope): void
    {
        $this->database->transaction(function () use ($requestId, $leaseToken, $sourceId, $scope): void {
            $request = $this->authorizedRequest($requestId, $leaseToken);
            $runSource = $this->runSource($requestId, $sourceId, true);
            if (!in_array((string) $runSource['source_status'], ['planned', 'waiting_for_login'], true)) {
                throw new \InvalidArgumentException('Zdroj nelze v aktuálním stavu zahájit.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->database->query('UPDATE search_run_sources SET', [
                'source_status' => 'running',
                'query_text' => trim($scope->description),
                'filters_json' => $scope->filters === [] ? null : json_encode($scope->filters, JSON_THROW_ON_ERROR),
                'horizon_from' => $scope->horizonFrom,
                'horizon_to' => $scope->horizonTo,
                'started_at' => $now,
                'finished_at' => null,
                'incomplete_reason' => null,
                'error_code' => null,
                'login_required' => false,
            ], 'WHERE id = ?', $runSource['id']);
            $this->database->query(
                'UPDATE search_requests SET request_status = ? WHERE id = ?',
                'running',
                $requestId,
            );
            $this->auditLogger->record('search.source_started', null, [
                'search_request_id' => $requestId,
                'search_run_id' => (int) $runSource['search_run_id'],
                'source_id' => $sourceId,
                'runner_device_id' => (int) $request['runner_device_id'],
            ]);
        });
    }

    public function startRunSource(int $runnerDeviceId, int $runId, string $leaseToken, int $sourceId, SourceRunScope $scope): void
    {
        $requestId = $this->requestIdForRun($runId, $runnerDeviceId);
        $this->startSource($requestId, $leaseToken, $sourceId, $scope);
    }

    public function finishSource(int $requestId, string $leaseToken, int $sourceId, SourceRunResult $result): void
    {
        $this->database->transaction(function () use ($requestId, $leaseToken, $sourceId, $result): void {
            $request = $this->authorizedRequest($requestId, $leaseToken);
            $runSource = $this->runSource($requestId, $sourceId, true);
            if ($runSource['source_status'] !== 'running' || $runSource['started_at'] === null) {
                throw new \InvalidArgumentException('Dokončit lze pouze skutečně zahájený zdroj.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->database->query('UPDATE search_run_sources SET', [
                'source_status' => $result->status,
                'pages_traversed' => $result->pagesTraversed,
                'displayed_count' => $result->displayedCount,
                'detail_opened_count' => $result->detailOpenedCount,
                'stored_count' => $result->storedCount,
                'updated_count' => $result->updatedCount,
                'duplicate_count' => $result->duplicateCount,
                'rejected_count' => $result->rejectedCount,
                'finished_at' => $now,
                'incomplete_reason' => $this->nullable($result->incompleteReason),
                'error_code' => $this->nullable($result->errorCode),
                'login_required' => $result->status === 'waiting_for_login',
            ], 'WHERE id = ?', $runSource['id']);
            $this->updateAccessState($sourceId, (int) $request['runner_device_id'], $result, $now);
            $this->advanceRequest($requestId, (int) $runSource['search_run_id'], $now);
            $this->auditLogger->record('search.source_finished', null, [
                'search_request_id' => $requestId,
                'search_run_id' => (int) $runSource['search_run_id'],
                'source_id' => $sourceId,
                'runner_device_id' => (int) $request['runner_device_id'],
                'status' => $result->status,
                'pages_traversed' => $result->pagesTraversed,
                'displayed_count' => $result->displayedCount,
                'stored_count' => $result->storedCount,
            ]);
        });
    }

    public function finishRunSource(int $runnerDeviceId, int $runId, string $leaseToken, int $sourceId, SourceRunResult $result): void
    {
        $requestId = $this->requestIdForRun($runId, $runnerDeviceId);
        $this->finishSource($requestId, $leaseToken, $sourceId, $result);
    }

    private function requestIdForRun(int $runId, int $runnerDeviceId): int
    {
        $requestId = $this->database->fetchField(
            'SELECT search_request_id FROM search_runs WHERE id = ? AND runner_device_id = ?',
            $runId,
            $runnerDeviceId,
        );
        if ($requestId === null) {
            throw new \InvalidArgumentException('Běh kontroly nebyl nalezen.');
        }
        return (int) $requestId;
    }

    private function authorizedRequest(int $requestId, string $leaseToken): Row
    {
        $request = $this->database->fetch(
            'SELECT runner_device_id, lease_token_hash, lease_expires_at FROM search_requests WHERE id = ? FOR UPDATE',
            $requestId,
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$request instanceof Row
            || $request['lease_token_hash'] === null
            || !hash_equals((string) $request['lease_token_hash'], hash('sha256', $leaseToken))
            || !$request['lease_expires_at'] instanceof \DateTimeInterface
            || $request['lease_expires_at'] <= $now
        ) {
            throw new \InvalidArgumentException('Lease neexistuje, neodpovídá nebo vypršel.');
        }
        return $request;
    }

    private function runSource(int $requestId, int $sourceId, bool $lock): Row
    {
        $row = $this->database->fetch(
            'SELECT run_source.* FROM search_run_sources run_source
             INNER JOIN search_runs run ON run.id = run_source.search_run_id
             WHERE run.search_request_id = ? AND run_source.source_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            $requestId,
            $sourceId,
        );
        if (!$row instanceof Row) {
            throw new \InvalidArgumentException('Zdroj nepatří do tohoto běhu.');
        }
        return $row;
    }

    private function updateAccessState(int $sourceId, int $runnerDeviceId, SourceRunResult $result, \DateTimeImmutable $now): void
    {
        $status = match ($result->status) {
            'complete' => 'available',
            'waiting_for_login' => 'login_required',
            'error' => 'error',
            default => 'unknown',
        };
        $this->database->query(
            'INSERT INTO source_access_states
                (source_id, access_status, verified_at, verification_origin, intervention_required, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE access_status = VALUES(access_status), verified_at = VALUES(verified_at),
                verification_origin = VALUES(verification_origin), intervention_required = VALUES(intervention_required),
                updated_at = VALUES(updated_at)',
            $sourceId,
            $status,
            $now,
            'runner:' . $runnerDeviceId,
            $result->status === 'waiting_for_login',
            $now,
        );
    }

    private function advanceRequest(int $requestId, int $runId, \DateTimeImmutable $now): void
    {
        $waitingForLogin = (int) $this->database->fetchField(
            "SELECT COUNT(*) FROM search_run_sources WHERE search_run_id = ? AND source_status = 'waiting_for_login'",
            $runId,
        );
        if ($waitingForLogin > 0) {
            $this->database->query('UPDATE search_requests SET request_status = ? WHERE id = ?', 'waiting_for_login', $requestId);
            return;
        }
        $open = (int) $this->database->fetchField(
            "SELECT COUNT(*) FROM search_run_sources WHERE search_run_id = ? AND source_status IN ('planned', 'running', 'waiting_for_login')",
            $runId,
        );
        if ($open > 0) {
            $this->database->query('UPDATE search_requests SET request_status = ? WHERE id = ?', 'running', $requestId);
            return;
        }
        $incomplete = (int) $this->database->fetchField(
            "SELECT COUNT(*) FROM search_run_sources WHERE search_run_id = ? AND source_status <> 'complete'",
            $runId,
        );
        $status = $incomplete === 0 ? 'complete' : 'partial';
        $this->database->query(
            'UPDATE search_runs SET run_status = ?, finished_at = ?, completion_reason = ? WHERE id = ?',
            $status,
            $now,
            $status === 'complete' ? null : 'Jeden nebo více zdrojů nebylo dokončeno.',
            $runId,
        );
        $this->database->query(
            'UPDATE search_requests SET request_status = ?, completed_at = ?, lease_token_hash = NULL, lease_expires_at = NULL WHERE id = ?',
            $status,
            $now,
            $requestId,
        );
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
