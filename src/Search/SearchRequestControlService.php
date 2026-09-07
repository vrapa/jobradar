<?php

declare(strict_types=1);

namespace App\Search;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class SearchRequestControlService
{
    private const TERMINAL_STATUSES = ['complete', 'partial', 'cancelled', 'error'];

    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function requestResume(int $userId, int $requestId): string
    {
        /** @var string */
        return $this->database->transaction(function () use ($userId, $requestId): string {
            $request = $this->ownedRequest($userId, $requestId);
            $status = (string) $request['request_status'];
            if ($status === 'resume_requested') {
                return $status;
            }
            if ($status !== 'waiting_for_login') {
                throw new \InvalidArgumentException('Pokračovat lze pouze u kontroly čekající na přihlášení.');
            }
            $this->database->query(
                'UPDATE search_requests SET request_status = ?, lease_token_hash = NULL, lease_expires_at = NULL WHERE id = ?',
                'resume_requested',
                $requestId,
            );
            $this->auditLogger->record('search.resume_requested', $userId, ['search_request_id' => $requestId]);
            return 'resume_requested';
        });
    }

    public function cancel(int $userId, int $requestId): string
    {
        /** @var string */
        return $this->database->transaction(function () use ($userId, $requestId): string {
            $request = $this->ownedRequest($userId, $requestId);
            $status = (string) $request['request_status'];
            if ($status === 'cancelled') {
                return $status;
            }
            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                throw new \InvalidArgumentException('Dokončenou kontrolu už nelze zrušit.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $run = $this->database->fetch('SELECT id FROM search_runs WHERE search_request_id = ? FOR UPDATE', $requestId);
            if ($run instanceof Row) {
                $runId = (int) $run['id'];
                $this->database->query(
                    "UPDATE search_run_sources SET source_status = 'cancelled', finished_at = ?,
                        incomplete_reason = COALESCE(incomplete_reason, 'Zrušeno uživatelem.')
                     WHERE search_run_id = ? AND source_status IN ('planned', 'running', 'waiting_for_login')",
                    $now,
                    $runId,
                );
                $this->database->query(
                    'UPDATE search_runs SET run_status = ?, finished_at = ?, completion_reason = ? WHERE id = ?',
                    'cancelled',
                    $now,
                    'Zrušeno uživatelem.',
                    $runId,
                );
            }
            $this->database->query(
                'UPDATE search_requests SET request_status = ?, cancelled_at = ?, completed_at = ?,
                    lease_token_hash = NULL, lease_expires_at = NULL WHERE id = ?',
                'cancelled',
                $now,
                $now,
                $requestId,
            );
            $this->auditLogger->record('search.cancelled', $userId, ['search_request_id' => $requestId]);
            return 'cancelled';
        });
    }

    private function ownedRequest(int $userId, int $requestId): Row
    {
        $request = $this->database->fetch(
            'SELECT request_status FROM search_requests WHERE id = ? AND requested_by_user_id = ? FOR UPDATE',
            $requestId,
            $userId,
        );
        if (!$request instanceof Row) {
            throw new \InvalidArgumentException('Kontrola nebyla nalezena.');
        }
        return $request;
    }
}
