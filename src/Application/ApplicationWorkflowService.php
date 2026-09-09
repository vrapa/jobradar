<?php

declare(strict_types=1);

namespace App\Application;

use App\Action\ActionItemService;
use App\Infrastructure\AuditLogger;
use App\Opportunity\OpportunityConflictException;
use Nette\Database\Connection;
use Nette\Database\Row;

final class ApplicationWorkflowService
{
    public const LABELS = ['none' => 'K přípravě', 'preparing' => 'Připravujeme reakci',
        'awaiting_approval' => 'Připraveno ke schválení', 'submitted' => 'Odesláno',
        'awaiting_response' => 'Čekáme na odpověď', 'response_received' => 'Odpověď přijata', 'closed' => 'Uzavřeno'];

    public function __construct(private readonly Connection $database, private readonly ActionItemService $actions, private readonly AuditLogger $audit)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function record(int $userId, int $opportunityId, array $input, string $actor = 'user'): array
    {
        $allowed = ['event', 'expected_lock_version', 'idempotency_key', 'reference', 'occurred_at', 'channel', 'approval_reference', 'follow_up_at', 'complete_action_item_ids'];
        if (array_diff(array_keys($input), $allowed) !== [] || !in_array($actor, ['user', 'assistant'], true)) {
            throw new \InvalidArgumentException('Událost obsahuje neznámá pole nebo neplatného autora.');
        }
        $event = $input['event'] ?? null;
        $key = $input['idempotency_key'] ?? null;
        if (!is_string($event) || !in_array($event, ['prepared', 'submitted', 'response_received', 'closed'], true)
            || !is_string($key) || strlen($key) < 16 || strlen($key) > 200
            || !is_int($input['expected_lock_version'] ?? null) || $input['expected_lock_version'] < 1) {
            throw new \InvalidArgumentException('Chybí platná událost, verze stavu nebo idempotency klíč.');
        }
        self::requiredText($input, 'reference');
        $occurredAt = self::date($input['occurred_at'] ?? null);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($occurredAt > $now) {
            throw new \InvalidArgumentException('Událost nemůže být v budoucnosti.');
        }
        $followUp = null;
        if ($event === 'submitted') {
            self::requiredText($input, 'approval_reference');
            if (!in_array($input['channel'] ?? null, ['portal', 'email', 'other'], true)) {
                throw new \InvalidArgumentException('Zvolte kanál skutečného odeslání.');
            }
            $followUp = self::date($input['follow_up_at'] ?? null);
            if ($followUp <= $occurredAt) {
                throw new \InvalidArgumentException('Kontrola odpovědi musí následovat po odeslání.');
            }
        } elseif (isset($input['channel']) || isset($input['approval_reference']) || isset($input['follow_up_at']) || !empty($input['complete_action_item_ids'])) {
            throw new \InvalidArgumentException('Údaje o odeslání patří pouze k události odesláno.');
        }
        $completeIds = $input['complete_action_item_ids'] ?? [];
        if (!is_array($completeIds) || !array_is_list($completeIds) || count($completeIds) > 100) {
            throw new \InvalidArgumentException('Seznam dokončených úkolů není platný.');
        }
        foreach ($completeIds as $id) {
            if (!is_int($id) || $id < 1) { throw new \InvalidArgumentException('ID úkolu není platné.'); }
        }
        ksort($input);
        $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $opportunityId . ':' . $json);

        return $this->database->transaction(function () use ($userId, $opportunityId, $input, $event, $key, $hash, $json, $actor, $now, $occurredAt, $followUp, $completeIds): array {
            $state = $this->database->fetch('SELECT s.* FROM user_opportunity_state s JOIN opportunities o ON o.id=s.opportunity_id WHERE s.user_id=? AND s.opportunity_id=? AND o.archived_at IS NULL FOR UPDATE', $userId, $opportunityId);
            if (!$state instanceof Row) { throw new \InvalidArgumentException('Nejdříve u nabídky zvolte Reagovat.'); }
            $existing = $this->database->fetch('SELECT request_hash,result_json FROM application_events WHERE user_id=? AND idempotency_key=?', $userId, $key);
            if ($existing instanceof Row) {
                if ($existing['request_hash'] !== $hash) { throw new OpportunityConflictException('Klíč byl použit pro jinou událost.'); }
                return json_decode($existing['result_json'], true, flags: JSON_THROW_ON_ERROR);
            }
            if ((int) $state['lock_version'] !== $input['expected_lock_version']) { throw new OpportunityConflictException('Stav se mezitím změnil. Obnovte nabídku.'); }
            $previous = (string) $state['workflow_status'];
            $allowedStates = match ($event) {
                'prepared' => ['none', 'preparing', 'awaiting_approval'],
                'submitted' => ['none', 'preparing', 'awaiting_approval'],
                'response_received' => ['submitted', 'awaiting_response'],
                'closed' => ['none', 'preparing', 'awaiting_approval', 'submitted', 'awaiting_response', 'response_received'],
            };
            if (!in_array($previous, $allowedStates, true) || (in_array($event, ['prepared', 'submitted'], true) && $state['decision'] !== 'react')) {
                throw new \InvalidArgumentException('Tento přechod ze současného stavu není možný.');
            }
            $latest = $this->database->fetch('SELECT payload_json FROM application_events WHERE user_id=? AND opportunity_id=? ORDER BY id DESC LIMIT 1', $userId, $opportunityId);
            if ($latest instanceof Row) {
                $last = json_decode($latest['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                if ($occurredAt < self::date($last['occurred_at'])) { throw new \InvalidArgumentException('Událost předchází poslední zaznamenané události.'); }
            }
            foreach (array_unique($completeIds) as $id) {
                $item = $this->database->fetch('SELECT * FROM action_items WHERE id=? AND user_id=? AND opportunity_id=? FOR UPDATE', $id, $userId, $opportunityId);
                if (!$item instanceof Row || $item['action_type'] === 'follow_up' || $item['status'] === 'cancelled') { throw new \InvalidArgumentException('Dokončit lze jen přípravný úkol této nabídky.'); }
                $this->actions->complete($userId, $id, $actor);
            }
            $next = match ($event) { 'prepared' => 'awaiting_approval', 'submitted' => 'awaiting_response', default => $event };
            $followUpId = null;
            if ($event === 'submitted') {
                $title = (string) $this->database->fetchField('SELECT COALESCE(v.translated_title,v.original_title) FROM opportunities o JOIN source_versions v ON v.id=o.current_source_version_id WHERE o.id=?', $opportunityId);
                $followUpId = $this->actions->create($userId, $opportunityId, 'follow_up', mb_substr('Ověřit odpověď: ' . $title, 0, 255), 'Zkontrolovat, zda přišla odpověď na odeslanou reakci.', $followUp, 'system');
            }
            if (in_array($event, ['response_received', 'closed'], true)) {
                // Only the follow-up created by this application's submission is superseded.
                $submissions = $this->database->fetchAll("SELECT result_json FROM application_events WHERE user_id=? AND opportunity_id=? AND event_type='submitted'", $userId, $opportunityId);
                foreach ($submissions as $submission) {
                    $result = json_decode($submission['result_json'], true, flags: JSON_THROW_ON_ERROR);
                    $itemId = $result['follow_up_action_item_id'] ?? null;
                    if (is_int($itemId) && $this->database->fetchField('SELECT status FROM action_items WHERE id=? AND user_id=?', $itemId, $userId) === 'open') {
                        $this->actions->complete($userId, $itemId, $actor);
                    }
                }
            }
            $version = (int) $state['lock_version'] + 1;
            $this->database->query('UPDATE user_opportunity_state SET', ['workflow_status' => $next, 'lock_version' => $version, 'updated_at' => $now], 'WHERE user_id=? AND opportunity_id=?', $userId, $opportunityId);
            $result = ['opportunity_id' => $opportunityId, 'workflow_status' => $next, 'lock_version' => $version, 'follow_up_action_item_id' => $followUpId, 'decision_changed' => false, 'sent_by_this_operation' => false];
            $this->database->query('INSERT INTO application_events', ['user_id' => $userId, 'opportunity_id' => $opportunityId, 'event_type' => $event, 'previous_status' => $previous, 'workflow_status' => $next, 'idempotency_key' => $key, 'request_hash' => $hash, 'payload_json' => $json, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'actor_type' => $actor, 'created_at' => $now]);
            $this->audit->record('application.' . $event, $userId, ['opportunity_id' => $opportunityId, 'event_id' => (int) $this->database->getInsertId(), 'actor_type' => $actor, 'workflow_status' => $next]);
            return $result;
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(int $userId, int $opportunityId): array
    {
        return array_map(static function (Row $row): array {
            return ['id' => (int) $row['id'], 'event' => $row['event_type'], 'workflow_status' => $row['workflow_status'], 'actor_type' => $row['actor_type'], 'created_at' => $row['created_at']->format(DATE_ATOM), 'details' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR)];
        }, $this->database->fetchAll('SELECT * FROM application_events WHERE user_id=? AND opportunity_id=? ORDER BY id DESC', $userId, $opportunityId));
    }

    /** @param array<string,mixed> $input */
    private static function requiredText(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) { throw new \InvalidArgumentException('Chybí stručný doložený odkaz nebo potvrzení: ' . $key); }
        return trim($value);
    }

    public static function date(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) { throw new \InvalidArgumentException('Čas musí mít ISO formát s časovým pásmem.'); }
        try { $date = new \DateTimeImmutable($value); } catch (\Exception) { throw new \InvalidArgumentException('Neplatný čas.'); }
        if (\DateTimeImmutable::getLastErrors() !== false) { throw new \InvalidArgumentException('Neplatné datum.'); }
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
