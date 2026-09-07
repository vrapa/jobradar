<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Auth\ApiIdentity;
use Nette\Database\Connection;
use Nette\Database\Row;

final class AssistantActionService
{
    public function __construct(private readonly Connection $database)
    {
    }

    /** @param array<string, scalar|null> $request */
    public function reserve(
        ApiIdentity $identity,
        string $idempotencyKey,
        string $actionType,
        string $targetType,
        int $targetId,
        array $request,
    ): AssistantActionReservation {
        $idempotencyKey = trim($idempotencyKey);
        if (mb_strlen($idempotencyKey) < 16 || mb_strlen($idempotencyKey) > 200) {
            throw new \InvalidArgumentException('Idempotency klíč musí mít 16 až 200 znaků.');
        }
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = self::hashRequest($request);
        $existing = $this->find($identity->clientIdentifier, $keyHash);
        if ($existing instanceof Row) {
            return $this->replay($existing, $actionType, $targetType, $targetId, $requestHash);
        }

        $summary = ['request_hash' => $requestHash];
        try {
            $this->database->query('INSERT INTO assistant_actions', [
                'client_identifier' => $identity->clientIdentifier,
                'action_type' => $actionType,
                'idempotency_key_hash' => $keyHash,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'result_status' => 'accepted',
                'result_summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                'created_at' => self::now(),
            ]);
        } catch (\Throwable $exception) {
            $existing = $this->find($identity->clientIdentifier, $keyHash);
            if (!$existing instanceof Row) {
                throw $exception;
            }
            return $this->replay($existing, $actionType, $targetType, $targetId, $requestHash);
        }
        return new AssistantActionReservation(
            (int) $this->database->getInsertId(),
            true,
            'accepted',
        );
    }

    /** @param array<string, mixed> $response */
    public function complete(int $actionId, int $httpStatus, array $response): void
    {
        $this->finish($actionId, 'completed', $httpStatus, $response);
    }

    /** @param array<string, mixed> $response */
    public function reject(int $actionId, string $status, int $httpStatus, array $response): void
    {
        if (!in_array($status, ['rejected', 'conflict', 'failed'], true)) {
            throw new \LogicException('Neplatný výsledný stav akce asistenta.');
        }
        $this->finish($actionId, $status, $httpStatus, $response);
    }

    /** @param array<string, mixed> $response */
    private function finish(int $actionId, string $status, int $httpStatus, array $response): void
    {
        $row = $this->database->fetch('SELECT result_summary_json FROM assistant_actions WHERE id = ?', $actionId);
        if (!$row instanceof Row) {
            throw new \LogicException('Akce asistenta nebyla nalezena.');
        }
        $summary = self::decodeSummary($row['result_summary_json']);
        $summary['http_status'] = $httpStatus;
        $summary['response'] = $response;
        $this->database->query('UPDATE assistant_actions SET', [
            'result_status' => $status,
            'result_summary_json' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], 'WHERE id = ? AND result_status = ?', $actionId, 'accepted');
    }

    private function find(string $clientIdentifier, string $keyHash): ?Row
    {
        $row = $this->database->fetch(
            'SELECT id, action_type, target_type, target_id, result_status, result_summary_json
             FROM assistant_actions WHERE client_identifier = ? AND idempotency_key_hash = ?',
            $clientIdentifier,
            $keyHash,
        );
        return $row instanceof Row ? $row : null;
    }

    private function replay(
        Row $row,
        string $actionType,
        string $targetType,
        int $targetId,
        string $requestHash,
    ): AssistantActionReservation {
        $summary = self::decodeSummary($row['result_summary_json']);
        if ((string) $row['action_type'] !== $actionType
            || (string) $row['target_type'] !== $targetType
            || (int) $row['target_id'] !== $targetId
            || ($summary['request_hash'] ?? null) !== $requestHash
        ) {
            throw new \InvalidArgumentException('Idempotency klíč už byl použit pro jiný požadavek.');
        }
        $httpStatus = $summary['http_status'] ?? null;
        $response = $summary['response'] ?? null;
        return new AssistantActionReservation(
            (int) $row['id'],
            false,
            (string) $row['result_status'],
            is_int($httpStatus) ? $httpStatus : null,
            is_array($response) ? $response : null,
        );
    }

    /** @param array<string, scalar|null> $request */
    private static function hashRequest(array $request): string
    {
        ksort($request);
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private static function decodeSummary(mixed $json): array
    {
        if (!is_string($json)) {
            throw new \UnexpectedValueException('Výsledek akce asistenta není platný.');
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Výsledek akce asistenta není platný.');
        }
        return $decoded;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
