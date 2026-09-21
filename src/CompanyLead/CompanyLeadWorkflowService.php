<?php

declare(strict_types=1);

namespace App\CompanyLead;

use App\Action\ActionItemService;
use App\Application\ApplicationWorkflowService;
use App\Infrastructure\AuditLogger;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityType;
use Nette\Database\Connection;
use Nette\Database\Row;

final class CompanyLeadWorkflowService
{
    public const LABELS = [
        'new' => 'Nový',
        'awaiting_approval' => 'Připraveno ke schválení',
        'awaiting_response' => 'Čekáme na odpověď',
        'response_received' => 'Odpověď přijata',
        'closed' => 'Uzavřeno',
    ];

    public function __construct(private readonly Connection $database, private readonly ActionItemService $actions, private readonly AuditLogger $audit)
    {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function record(int $userId, int $opportunityId, array $input, string $actor = 'user'): array
    {
        $allowed = ['event', 'expected_lock_version', 'idempotency_key', 'reference', 'occurred_at', 'channel', 'approval_reference', 'follow_up_at', 'complete_action_item_ids'];
        if (array_diff(array_keys($input), $allowed) !== [] || !in_array($actor, ['user', 'assistant'], true)) {
            throw new \InvalidArgumentException('Událost leadu obsahuje neznámá pole nebo neplatného autora.');
        }
        $event = $input['event'] ?? null;
        $key = $input['idempotency_key'] ?? null;
        if (!is_string($event) || !in_array($event, ['prepared', 'contacted', 'response_received', 'closed'], true)
            || !is_string($key) || strlen($key) < 16 || strlen($key) > 200
            || !is_int($input['expected_lock_version'] ?? null) || $input['expected_lock_version'] < 0) {
            throw new \InvalidArgumentException('Chybí platná událost, verze stavu nebo idempotency klíč.');
        }
        self::requiredText($input, 'reference', 2000);
        $occurredAt = ApplicationWorkflowService::date($input['occurred_at'] ?? null);
        $now = self::now();
        if ($occurredAt > $now) {
            throw new \InvalidArgumentException('Událost nemůže být v budoucnosti.');
        }
        $followUp = null;
        if ($event === 'contacted') {
            self::requiredText($input, 'approval_reference', 2000);
            self::requiredText($input, 'channel', 100);
            $followUp = ApplicationWorkflowService::date($input['follow_up_at'] ?? null);
            if ($followUp <= $occurredAt) {
                throw new \InvalidArgumentException('Kontrola odpovědi musí následovat po oslovení.');
            }
        } elseif (isset($input['channel']) || isset($input['approval_reference']) || isset($input['follow_up_at']) || !empty($input['complete_action_item_ids'])) {
            throw new \InvalidArgumentException('Údaje o odeslání patří pouze k události osloveno.');
        }
        $completeIds = $input['complete_action_item_ids'] ?? [];
        if (!is_array($completeIds) || !array_is_list($completeIds) || count($completeIds) > 100) {
            throw new \InvalidArgumentException('Seznam dokončených úkolů není platný.');
        }
        foreach ($completeIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('ID úkolu není platné.');
            }
        }
        ksort($input);
        $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $opportunityId . ':' . $json);

        return $this->database->transaction(function () use ($userId, $opportunityId, $input, $event, $key, $hash, $json, $actor, $now, $occurredAt, $followUp, $completeIds): array {
            $opportunity = $this->database->fetch('SELECT id FROM opportunities WHERE id = ? AND opportunity_type = ? AND archived_at IS NULL FOR UPDATE', $opportunityId, OpportunityType::COMPANY_LEAD);
            if (!$opportunity instanceof Row) {
                throw new \InvalidArgumentException('Firemní kontakt / lead nebyl nalezen.');
            }
            $existing = $this->database->fetch('SELECT request_hash, result_json FROM company_lead_events WHERE user_id = ? AND idempotency_key = ?', $userId, $key);
            if ($existing instanceof Row) {
                if ((string) $existing['request_hash'] !== $hash) {
                    throw new OpportunityConflictException('Klíč byl použit pro jinou událost leadu.');
                }
                return json_decode((string) $existing['result_json'], true, flags: JSON_THROW_ON_ERROR);
            }
            $state = $this->database->fetch('SELECT * FROM company_lead_states WHERE user_id = ? AND opportunity_id = ? FOR UPDATE', $userId, $opportunityId);
            $previous = $state instanceof Row ? (string) $state['workflow_status'] : 'new';
            $lockVersion = $state instanceof Row ? (int) $state['lock_version'] : 0;
            if ($lockVersion !== $input['expected_lock_version']) {
                throw new OpportunityConflictException('Stav leadu se mezitím změnil. Obnovte detail.');
            }
            $allowedStates = match ($event) {
                'prepared' => ['new', 'awaiting_approval'],
                'contacted' => ['awaiting_approval'],
                'response_received' => ['awaiting_response', 'closed'],
                'closed' => ['new', 'awaiting_approval', 'awaiting_response', 'response_received'],
            };
            if (!in_array($previous, $allowedStates, true)) {
                throw new \InvalidArgumentException('Tento přechod stavu firemního leadu není možný.');
            }
            $latest = $this->database->fetch('SELECT payload_json FROM company_lead_events WHERE user_id = ? AND opportunity_id = ? ORDER BY id DESC LIMIT 1', $userId, $opportunityId);
            if ($latest instanceof Row) {
                $last = json_decode((string) $latest['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                if ($occurredAt < ApplicationWorkflowService::date($last['occurred_at'])) {
                    throw new \InvalidArgumentException('Událost předchází poslední zaznamenané události leadu.');
                }
            }
            foreach (array_unique($completeIds) as $id) {
                $item = $this->database->fetch('SELECT * FROM action_items WHERE id = ? AND user_id = ? AND opportunity_id = ? FOR UPDATE', $id, $userId, $opportunityId);
                if (!$item instanceof Row || $item['action_type'] === 'follow_up' || $item['status'] === 'cancelled') {
                    throw new \InvalidArgumentException('Dokončit lze jen přípravný úkol tohoto leadu.');
                }
                $this->actions->complete($userId, $id, $actor);
            }
            $next = match ($event) {
                'prepared' => 'awaiting_approval',
                'contacted' => 'awaiting_response',
                default => $event,
            };
            $followUpId = null;
            if ($event === 'contacted') {
                $title = (string) $this->database->fetchField('SELECT COALESCE(v.translated_title, v.original_title) FROM opportunities o JOIN source_versions v ON v.id = o.current_source_version_id WHERE o.id = ?', $opportunityId);
                $followUpId = $this->actions->create($userId, $opportunityId, 'follow_up', mb_substr('Zkontrolovat odpověď: ' . $title, 0, 255), 'Zkontrolovat, zda přišla odpověď na schválené firemní oslovení.', $followUp, 'system');
            }
            if (in_array($event, ['response_received', 'closed'], true)) {
                foreach ($this->database->fetchAll("SELECT result_json FROM company_lead_events WHERE user_id = ? AND opportunity_id = ? AND event_type = 'contacted'", $userId, $opportunityId) as $contacted) {
                    $result = json_decode((string) $contacted['result_json'], true, flags: JSON_THROW_ON_ERROR);
                    $itemId = $result['follow_up_action_item_id'] ?? null;
                    if (is_int($itemId) && $this->database->fetchField('SELECT status FROM action_items WHERE id = ? AND user_id = ?', $itemId, $userId) === 'open') {
                        $this->actions->complete($userId, $itemId, $actor);
                    }
                }
            }
            $version = $lockVersion + 1;
            if ($state instanceof Row) {
                $this->database->query('UPDATE company_lead_states SET', ['workflow_status' => $next, 'lock_version' => $version, 'updated_at' => $now], 'WHERE user_id = ? AND opportunity_id = ?', $userId, $opportunityId);
            } else {
                $this->database->query('INSERT INTO company_lead_states', ['user_id' => $userId, 'opportunity_id' => $opportunityId, 'workflow_status' => $next, 'lock_version' => $version, 'updated_at' => $now]);
            }
            $result = ['opportunity_id' => $opportunityId, 'workflow_status' => $next, 'lock_version' => $version, 'follow_up_action_item_id' => $followUpId, 'sent_by_this_operation' => false];
            $this->database->query('INSERT INTO company_lead_events', ['user_id' => $userId, 'opportunity_id' => $opportunityId, 'event_type' => $event, 'previous_status' => $previous, 'workflow_status' => $next, 'idempotency_key' => $key, 'request_hash' => $hash, 'payload_json' => $json, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'actor_type' => $actor, 'created_at' => $now]);
            $this->audit->record('company_lead.' . $event, $userId, ['opportunity_id' => $opportunityId, 'event_id' => (int) $this->database->getInsertId(), 'actor_type' => $actor, 'workflow_status' => $next]);
            return $result;
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(int $userId, int $opportunityId): array
    {
        return array_map(static function (Row $row): array {
            return ['id' => (int) $row['id'], 'event' => (string) $row['event_type'], 'workflow_status' => (string) $row['workflow_status'], 'actor_type' => (string) $row['actor_type'], 'created_at' => $row['created_at']->format(DATE_ATOM), 'details' => json_decode((string) $row['payload_json'], true, flags: JSON_THROW_ON_ERROR)];
        }, $this->database->fetchAll('SELECT * FROM company_lead_events WHERE user_id = ? AND opportunity_id = ? ORDER BY id DESC', $userId, $opportunityId));
    }

    /** @return array{workflow_status:string,lock_version:int} */
    public function state(int $userId, int $opportunityId): array
    {
        $row = $this->database->fetch('SELECT workflow_status, lock_version FROM company_lead_states WHERE user_id = ? AND opportunity_id = ?', $userId, $opportunityId);
        return $row instanceof Row
            ? ['workflow_status' => (string) $row['workflow_status'], 'lock_version' => (int) $row['lock_version']]
            : ['workflow_status' => 'new', 'lock_version' => 0];
    }

    /** @param array<string,mixed> $input */
    private static function requiredText(array $input, string $key, int $maximum): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maximum) {
            throw new \InvalidArgumentException('Chybí platný doložený údaj: ' . $key);
        }
        return trim($value);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
