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

    public function claimNext(int $runnerDeviceId, int $ownerUserId): ?RunnerLease
    {
        /** @var RunnerLease|null */
        return $this->database->transaction(function () use ($runnerDeviceId, $ownerUserId): ?RunnerLease {
            $now = self::now();
            $device = $this->database->fetch(
                'SELECT id, device_status FROM runner_devices WHERE id = ? AND revoked_at IS NULL FOR UPDATE',
                $runnerDeviceId,
            );
            if (!$device instanceof Row || $device['device_status'] === 'revoked') {
                throw new \InvalidArgumentException('Runner zařízení neexistuje nebo bylo odvoláno.');
            }
            $this->touchDevice($runnerDeviceId, $now);
            if ($this->database->fetchField(
                "SELECT id FROM search_requests WHERE runner_device_id = ? AND lease_token_hash IS NOT NULL AND lease_expires_at > ? LIMIT 1",
                $runnerDeviceId, $now,
            ) !== null) {
                return null;
            }
            $request = $this->database->fetch(
                "SELECT id, request_status, requested_by_user_id FROM search_requests
                 WHERE requested_by_user_id = ? AND executor_eligible = 1 AND (request_status IN ('waiting_for_runner', 'resume_requested')
                    OR (request_status IN ('checking_access', 'running') AND lease_expires_at < ?))
                 ORDER BY requested_at, id LIMIT 1 FOR UPDATE SKIP LOCKED",
                $ownerUserId,
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
            $recoveredSourceCount = 0;
            if ($run instanceof Row) {
                $runId = (int) $run['id'];
                if (in_array((string) $request['request_status'], ['checking_access', 'running'], true)) {
                    $recoveredSourceCount = $this->recoverInterruptedSources($runId);
                }
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
                    'candidate_profile_id' => $this->database->fetchField('SELECT id FROM candidate_profiles WHERE created_by_user_id = ? AND valid_from <= ? AND (valid_until IS NULL OR valid_until > ?) ORDER BY version DESC, id DESC LIMIT 1', $request['requested_by_user_id'], $now, $now),
                    'scoring_rule_set_id' => $this->database->fetchField("SELECT id FROM scoring_rule_sets WHERE created_by_user_id = ? AND status IN ('active', 'draft') ORDER BY (status = 'active') DESC, version DESC, id DESC LIMIT 1", $request['requested_by_user_id']),
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
                    "SELECT rs.source_id FROM search_run_sources rs JOIN sources s ON s.id = rs.source_id
                     WHERE rs.search_run_id = ? AND rs.source_status IN ('planned', 'waiting_for_login') AND s.source_type NOT IN ('manual', 'manual_search')
                     ORDER BY s.priority, s.name, s.id",
                    $runId,
                ),
            );
            $this->touchDevice($runnerDeviceId, $now);
            $this->auditLogger->record('search.lease_claimed', null, [
                'search_request_id' => $requestId,
                'search_run_id' => $runId,
                'runner_device_id' => $runnerDeviceId,
                'source_count' => count($sourceIds),
                'recovered_source_count' => $recoveredSourceCount,
            ]);

            return new RunnerLease($requestId, $runId, $token, $expiresAt, $sourceIds);
        });
    }

    private function recoverInterruptedSources(int $runId): int
    {
        $result = $this->database->query(
            "UPDATE search_run_sources SET
                source_status = 'planned',
                pages_traversed = NULL,
                displayed_count = NULL,
                detail_opened_count = NULL,
                stored_count = NULL,
                updated_count = NULL,
                duplicate_count = NULL,
                rejected_count = NULL,
                finished_at = NULL,
                incomplete_reason = NULL,
                error_code = NULL,
                login_required = 0
             WHERE search_run_id = ? AND source_status = 'running'",
            $runId,
        );
        return (int) $result->getRowCount();
    }

    public function renew(int $runnerDeviceId, int $requestId, string $leaseToken): \DateTimeImmutable
    {
        /** @var \DateTimeImmutable */
        return $this->database->transaction(function () use ($runnerDeviceId, $requestId, $leaseToken): \DateTimeImmutable {
            $now = self::now();
            $request = $this->database->fetch(
                'SELECT request_status, runner_device_id, lease_token_hash, lease_expires_at
                 FROM search_requests WHERE id = ? FOR UPDATE',
                $requestId,
            );
            if (!$request instanceof Row
                || !in_array((string) $request['request_status'], ['checking_access', 'running'], true)
                || (int) $request['runner_device_id'] !== $runnerDeviceId
                || $request['lease_token_hash'] === null
                || !hash_equals((string) $request['lease_token_hash'], hash('sha256', $leaseToken))
                || !$request['lease_expires_at'] instanceof \DateTimeInterface
                || $request['lease_expires_at'] <= $now
            ) {
                throw new \InvalidArgumentException('Aktivní lease neexistuje, neodpovídá zařízení nebo vypršel.');
            }
            $expiresAt = $now->modify(sprintf('+%d minutes', self::LEASE_MINUTES));
            $this->database->query('UPDATE search_requests SET lease_expires_at = ? WHERE id = ?', $expiresAt, $requestId);
            $this->touchDevice($runnerDeviceId, $now);
            $this->auditLogger->record('search.lease_renewed', null, [
                'search_request_id' => $requestId,
                'runner_device_id' => $runnerDeviceId,
            ]);
            return $expiresAt;
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

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(gmdate('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }
}
