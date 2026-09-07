<?php

declare(strict_types=1);

namespace App\Search;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class RunnerLeaseService
{
    private const LEASE_MINUTES = 5;

    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function claimNext(int $runnerDeviceId): ?RunnerLease
    {
        /** @var RunnerLease|null */
        return $this->database->transaction(function () use ($runnerDeviceId): ?RunnerLease {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $device = $this->database->fetch(
                'SELECT id, device_status FROM runner_devices WHERE id = ? AND revoked_at IS NULL FOR UPDATE',
                $runnerDeviceId,
            );
            if (!$device instanceof Row || $device['device_status'] === 'revoked') {
                throw new \InvalidArgumentException('Runner zařízení neexistuje nebo bylo odvoláno.');
            }
            $request = $this->database->fetch(
                "SELECT id FROM search_requests
                 WHERE request_status IN ('waiting_for_runner', 'resume_requested')
                    OR (request_status IN ('checking_access', 'running') AND lease_expires_at < ?)
                 ORDER BY requested_at, id LIMIT 1 FOR UPDATE SKIP LOCKED",
                $now,
            );
            if (!$request instanceof Row) {
                $this->touchDevice($runnerDeviceId, $now);
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = $now->modify(sprintf('+%d minutes', self::LEASE_MINUTES));
            $requestId = (int) $request['id'];
            $this->database->query(
                'UPDATE search_requests SET request_status = ?, runner_device_id = ?, lease_token_hash = ?, lease_expires_at = ? WHERE id = ?',
                'checking_access',
                $runnerDeviceId,
                hash('sha256', $token),
                $expiresAt,
                $requestId,
            );
            $run = $this->database->fetch('SELECT id FROM search_runs WHERE search_request_id = ?', $requestId);
            if ($run instanceof Row) {
                $runId = (int) $run['id'];
                $this->database->query(
                    'UPDATE search_runs SET runner_device_id = ?, run_status = ? WHERE id = ?',
                    $runnerDeviceId,
                    'running',
                    $runId,
                );
            } else {
                $this->database->query('INSERT INTO search_runs', [
                    'search_request_id' => $requestId,
                    'runner_device_id' => $runnerDeviceId,
                    'candidate_profile_id' => null,
                    'scoring_rule_set_id' => null,
                    'run_status' => 'running',
                    'started_at' => $now,
                ]);
                $runId = (int) $this->database->getInsertId();
                $this->database->query(
                    "INSERT INTO search_run_sources (search_run_id, source_id, source_status)
                     SELECT ?, request_source.source_id, 'planned'
                     FROM search_request_sources request_source
                     WHERE request_source.search_request_id = ?",
                    $runId,
                    $requestId,
                );
            }
            $sourceIds = array_map(
                static fn (Row $row): int => (int) $row['source_id'],
                $this->database->fetchAll(
                    'SELECT source_id FROM search_run_sources WHERE search_run_id = ? ORDER BY source_id',
                    $runId,
                ),
            );
            $this->touchDevice($runnerDeviceId, $now);
            $this->auditLogger->record('search.lease_claimed', null, [
                'search_request_id' => $requestId,
                'search_run_id' => $runId,
                'runner_device_id' => $runnerDeviceId,
                'source_count' => count($sourceIds),
            ]);

            return new RunnerLease($requestId, $runId, $token, $expiresAt, $sourceIds);
        });
    }

    private function touchDevice(int $runnerDeviceId, \DateTimeImmutable $now): void
    {
        $this->database->query(
            'UPDATE runner_devices SET device_status = ?, last_seen_at = ? WHERE id = ?',
            'online',
            $now,
            $runnerDeviceId,
        );
    }
}
