<?php

declare(strict_types=1);

namespace App\Tender;

use App\Action\ActionItemService;
use App\Infrastructure\AuditLogger;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityType;
use Nette\Database\Connection;
use Nette\Database\Row;

final class TenderWorkflowService
{
    public const LABELS = [
        'active' => 'Aktivní',
        'closed' => 'Uzavřeno',
    ];

    public function __construct(
        private readonly Connection $database,
        private readonly ActionItemService $actions,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function record(int $userId, int $opportunityId, array $input, string $actor = 'user'): array
    {
        if (array_diff(array_keys($input), ['event', 'expected_lock_version', 'idempotency_key']) !== []
            || !in_array($actor, ['user', 'assistant'], true)
        ) {
            throw new \InvalidArgumentException('Změna stavu tenderu obsahuje neznámá pole nebo neplatného autora.');
        }
        $event = $input['event'] ?? null;
        $key = $input['idempotency_key'] ?? null;
        $expectedLockVersion = $input['expected_lock_version'] ?? null;
        if (!is_string($event) || !in_array($event, ['closed', 'reopened'], true)
            || !is_string($key) || strlen($key) < 16 || strlen($key) > 200
            || !is_int($expectedLockVersion) || $expectedLockVersion < 0
        ) {
            throw new \InvalidArgumentException('Chybí platná událost, verze stavu nebo idempotency klíč tenderu.');
        }

        ksort($input);
        $requestJson = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $requestHash = hash('sha256', $opportunityId . ':' . $requestJson);

        return $this->database->transaction(function () use ($userId, $opportunityId, $event, $key, $expectedLockVersion, $requestHash, $actor): array {
            $opportunity = $this->database->fetch(
                'SELECT id FROM opportunities WHERE id = ? AND opportunity_type = ? AND archived_at IS NULL FOR UPDATE',
                $opportunityId,
                OpportunityType::TENDER,
            );
            if (!$opportunity instanceof Row) {
                throw new \InvalidArgumentException('Výběrové řízení nebylo nalezeno.');
            }

            $existing = $this->database->fetch(
                'SELECT request_hash, result_json FROM tender_events WHERE user_id = ? AND idempotency_key = ?',
                $userId,
                $key,
            );
            if ($existing instanceof Row) {
                if ((string) $existing['request_hash'] !== $requestHash) {
                    throw new OpportunityConflictException('Idempotency klíč byl použit pro jinou změnu tenderu.');
                }
                $result = json_decode((string) $existing['result_json'], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($result)) {
                    throw new \UnexpectedValueException('Uložený výsledek změny tenderu není platný.');
                }
                return $result;
            }

            $state = $this->database->fetch(
                'SELECT workflow_status, lock_version FROM tender_states WHERE user_id = ? AND opportunity_id = ? FOR UPDATE',
                $userId,
                $opportunityId,
            );
            $previousStatus = $state instanceof Row ? (string) $state['workflow_status'] : 'active';
            $lockVersion = $state instanceof Row ? (int) $state['lock_version'] : 0;
            if ($lockVersion !== $expectedLockVersion) {
                throw new OpportunityConflictException('Stav výběrového řízení se mezitím změnil. Obnovte detail.');
            }
            if (($event === 'closed' && $previousStatus !== 'active')
                || ($event === 'reopened' && $previousStatus !== 'closed')
            ) {
                throw new \InvalidArgumentException('Tento přechod stavu výběrového řízení není možný.');
            }

            $cancelledActionItemIds = [];
            if ($event === 'closed') {
                $cancelledActionItemIds = array_map(
                    static fn (Row $row): int => (int) $row['id'],
                    $this->database->fetchAll(
                        "SELECT id FROM action_items WHERE user_id = ? AND opportunity_id = ? AND status = 'open' ORDER BY id FOR UPDATE",
                        $userId,
                        $opportunityId,
                    ),
                );
                foreach ($cancelledActionItemIds as $actionItemId) {
                    $this->actions->cancel($userId, $actionItemId, 'system');
                }
            }

            $status = $event === 'closed' ? 'closed' : 'active';
            $newLockVersion = $lockVersion + 1;
            $now = self::now();
            if ($state instanceof Row) {
                $this->database->query('UPDATE tender_states SET', [
                    'workflow_status' => $status,
                    'lock_version' => $newLockVersion,
                    'updated_at' => $now,
                ], 'WHERE user_id = ? AND opportunity_id = ?', $userId, $opportunityId);
            } else {
                $this->database->query('INSERT INTO tender_states', [
                    'user_id' => $userId,
                    'opportunity_id' => $opportunityId,
                    'workflow_status' => $status,
                    'lock_version' => $newLockVersion,
                    'updated_at' => $now,
                ]);
            }

            $result = [
                'opportunity_id' => $opportunityId,
                'workflow_status' => $status,
                'lock_version' => $newLockVersion,
                'cancelled_action_item_ids' => $cancelledActionItemIds,
                'decision_changed' => false,
                'application_submitted' => false,
            ];
            $this->database->query('INSERT INTO tender_events', [
                'user_id' => $userId,
                'opportunity_id' => $opportunityId,
                'event_type' => $event,
                'previous_status' => $previousStatus,
                'workflow_status' => $status,
                'previous_lock_version' => $lockVersion,
                'lock_version' => $newLockVersion,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'actor_type' => $actor,
                'created_at' => $now,
            ]);
            $eventId = (int) $this->database->getInsertId();
            $this->audit->record('tender.' . $event, $userId, [
                'opportunity_id' => $opportunityId,
                'event_id' => $eventId,
                'actor_type' => $actor,
                'workflow_status' => $status,
                'cancelled_action_item_count' => count($cancelledActionItemIds),
                'decision_changed' => false,
                'application_submitted' => false,
            ]);
            return $result;
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(int $userId, int $opportunityId): array
    {
        return array_map(static function (Row $row): array {
            return [
                'id' => (int) $row['id'],
                'event' => (string) $row['event_type'],
                'previous_status' => (string) $row['previous_status'],
                'workflow_status' => (string) $row['workflow_status'],
                'previous_lock_version' => (int) $row['previous_lock_version'],
                'lock_version' => (int) $row['lock_version'],
                'actor_type' => (string) $row['actor_type'],
                'created_at' => $row['created_at']->format(DATE_ATOM),
            ];
        }, $this->database->fetchAll(
            'SELECT * FROM tender_events WHERE user_id = ? AND opportunity_id = ? ORDER BY id DESC',
            $userId,
            $opportunityId,
        ));
    }

    /** @return array{workflow_status:string,lock_version:int} */
    public function state(int $userId, int $opportunityId): array
    {
        $row = $this->database->fetch(
            'SELECT workflow_status, lock_version FROM tender_states WHERE user_id = ? AND opportunity_id = ?',
            $userId,
            $opportunityId,
        );
        return $row instanceof Row
            ? ['workflow_status' => (string) $row['workflow_status'], 'lock_version' => (int) $row['lock_version']]
            : ['workflow_status' => 'active', 'lock_version' => 0];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
