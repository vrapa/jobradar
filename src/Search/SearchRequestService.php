<?php

declare(strict_types=1);

namespace App\Search;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;
use Nette\Database\UniqueConstraintViolationException;

final class SearchRequestService
{
    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param list<int> $sourceIds */
    public function request(int $userId, array $sourceIds, string $idempotencyKey, bool $prepareAccess = false): SearchRequestResult
    {
        $sourceIds = array_values(array_unique($sourceIds));
        sort($sourceIds, SORT_NUMERIC);
        if ($sourceIds === [] || array_any($sourceIds, static fn (int $id): bool => $id < 1)) {
            throw new \InvalidArgumentException('Vyberte alespoň jeden platný zdroj.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('Idempotency klíč musí mít 16 až 200 znaků.');
        }
        $keyHash = hash('sha256', $idempotencyKey);

        /** @var SearchRequestResult */
        return $this->database->transaction(function () use ($userId, $sourceIds, $keyHash, $prepareAccess): SearchRequestResult {
            $existing = $this->findExisting($userId, $keyHash);
            if ($existing instanceof Row) {
                if ((bool) $existing['prepare_access'] !== $prepareAccess) { throw new \InvalidArgumentException('Idempotency klíč už označuje jiný režim přípravy.'); }
                return $this->existingResult($existing, $sourceIds);
            }
            $this->assertSourcesAvailable($sourceIds);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            try {
                $this->database->query('INSERT INTO search_requests', [
                    'requested_by_user_id' => $userId,
                    'request_status' => 'waiting_for_runner',
                    'executor_eligible' => true,
                    'prepare_access' => $prepareAccess,
                    'idempotency_key_hash' => $keyHash,
                    'requested_at' => $now,
                ]);
                $requestId = (int) $this->database->getInsertId();
            } catch (UniqueConstraintViolationException) {
                $existing = $this->findExisting($userId, $keyHash);
                if (!$existing instanceof Row) {
                    throw new \RuntimeException('Souběžný požadavek se nepodařilo dohledat.');
                }
                if ((bool) $existing['prepare_access'] !== $prepareAccess) { throw new \InvalidArgumentException('Idempotency klíč už označuje jiný režim přípravy.'); }
                return $this->existingResult($existing, $sourceIds);
            }
            foreach ($sourceIds as $sourceId) {
                $this->database->query('INSERT INTO search_request_sources', [
                    'search_request_id' => $requestId,
                    'source_id' => $sourceId,
                    'search_definition_id' => $this->database->fetchField('SELECT id FROM source_search_definitions WHERE source_id = ? AND active = 1 AND archived_at IS NULL ORDER BY version DESC, id DESC LIMIT 1', $sourceId),
                ]);
            }
            $this->auditLogger->record('search.requested', $userId, [
                'search_request_id' => $requestId,
                'source_count' => count($sourceIds),
                'status' => 'waiting_for_runner',
            ]);

            return new SearchRequestResult($requestId, 'waiting_for_runner', true);
        });
    }

    private function findExisting(int $userId, string $keyHash): ?Row
    {
        return $this->database->fetch(
            'SELECT id, request_status, prepare_access FROM search_requests WHERE requested_by_user_id = ? AND idempotency_key_hash = ?',
            $userId,
            $keyHash,
        );
    }

    /** @param list<int> $sourceIds */
    private function existingResult(Row $existing, array $sourceIds): SearchRequestResult
    {
        $storedIds = array_map(
            static fn (Row $row): int => (int) $row['source_id'],
            $this->database->fetchAll(
                'SELECT source_id FROM search_request_sources WHERE search_request_id = ? ORDER BY source_id',
                $existing['id'],
            ),
        );
        sort($storedIds, SORT_NUMERIC);
        if ($storedIds !== $sourceIds) {
            throw new \InvalidArgumentException('Idempotency klíč už byl použit pro jiný výběr zdrojů.');
        }
        return new SearchRequestResult((int) $existing['id'], (string) $existing['request_status'], false);
    }

    /** @param list<int> $sourceIds */
    private function assertSourcesAvailable(array $sourceIds): void
    {
        $rows = $this->database->fetchAll(
            'SELECT id FROM sources WHERE id IN (?) AND active = 1 AND archived_at IS NULL AND source_type NOT IN (?, ?)',
            $sourceIds,
            'manual',
            'manual_search',
        );
        $found = array_map(static fn (Row $row): int => (int) $row['id'], $rows);
        sort($found, SORT_NUMERIC);
        if ($found !== $sourceIds) {
            throw new \InvalidArgumentException('Některý zdroj neexistuje, není aktivní nebo nepodporuje kontrolu.');
        }
    }
}
