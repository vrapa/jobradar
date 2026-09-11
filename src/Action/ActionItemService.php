<?php

declare(strict_types=1);

namespace App\Action;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class ActionItemService
{
    public const TYPES = [
        'prepare_applications' => 'Připravit reakce na nabídky',
        'review_opportunities' => 'Posoudit nové nabídky',
        'verify_attachment' => 'Prověřit přílohu',
        'verify_terms' => 'Ověřit podmínky',
        'review_application' => 'Zkontrolovat připravenou žádost',
        'reply' => 'Odpovědět',
        'follow_up' => 'Provést follow-up',
        'login' => 'Přihlásit se na portál',
        'other' => 'Jiný konkrétní krok',
    ];

    public function __construct(private readonly Connection $database, private readonly AuditLogger $auditLogger, private readonly string $appUrl)
    {
    }

    public function create(
        int $userId,
        ?int $opportunityId,
        string $type,
        string $title,
        ?string $details = null,
        ?\DateTimeInterface $dueAt = null,
        string $origin = 'user',
    ): int {
        $title = trim($title);
        $details = self::nullable($details);
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Typ navazujícího úkolu není platný.');
        }
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Název úkolu musí mít 1 až 255 znaků.');
        }
        if (!in_array($origin, ['user', 'assistant', 'system'], true)) {
            throw new \InvalidArgumentException('Původ úkolu není platný.');
        }
        if ($opportunityId !== null && !$this->database->fetchField('SELECT 1 FROM opportunities WHERE id = ? AND archived_at IS NULL', $opportunityId)) {
            throw new \InvalidArgumentException('Nabídka pro navazující úkol nebyla nalezena.');
        }

        $now = self::now();
        $id = 0;
        $this->database->transaction(function () use ($userId, $opportunityId, $type, $title, $details, $dueAt, $origin, $now, &$id): void {
            if (in_array($type, ['review_opportunities', 'prepare_applications'], true)) {
                // Serialize all summary creation for this owner, including simultaneous runs.
                $this->database->fetch('SELECT id FROM users WHERE id = ? FOR UPDATE', $userId);
                $existing = $this->database->fetchField("SELECT id FROM action_items WHERE user_id = ? AND action_type = ? AND status = 'open' FOR UPDATE", $userId, $type);
                if ($existing !== null) {
                    $id = (int) $existing;
                    return;
                }
                $opportunityId = null;
            }
            $this->database->query('INSERT INTO action_items', [
                'user_id' => $userId,
                'opportunity_id' => $opportunityId,
                'action_type' => $type,
                'title' => $title,
                'details' => $details,
                'due_at' => $dueAt,
                'status' => 'open',
                'origin' => $origin,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->database->getInsertId();
            $this->auditLogger->record('action_item.created', $userId, [
                'action_item_id' => $id,
                'opportunity_id' => $opportunityId,
                'action_type' => $type,
                'origin' => $origin,
            ]);
        });

        return $id;
    }

    /** Called inside the transaction that finishes a run; no external writes here. */
    public function reviewFinishedRun(int $runId): void
    {
        $this->database->transaction(function () use ($runId): void {
            $run = $this->database->fetch(
                'SELECT r.run_status, r.review_action_processed_at, q.requested_by_user_id
                 FROM search_runs r JOIN search_requests q ON q.id = r.search_request_id
                 WHERE r.id = ? FOR UPDATE', $runId,
            );
            if ($run === null || !in_array($run['run_status'], ['complete', 'partial'], true) || $run['review_action_processed_at'] !== null) {
                return;
            }
            $userId = (int) $run['requested_by_user_id'];
            $hasNew = $this->database->fetchField(
                "SELECT 1 FROM search_run_opportunities imported
                 JOIN opportunities o ON o.id = imported.opportunity_id
                 LEFT JOIN user_opportunity_state state ON state.opportunity_id = o.id AND state.user_id = ?
                 WHERE imported.search_run_id = ? AND imported.processing_result = 'created'
                   AND o.archived_at IS NULL AND COALESCE(state.decision, 'undecided') = 'undecided' LIMIT 1",
                $userId, $runId,
            );
            if ($hasNew !== null) {
                $id = $this->create($userId, null, 'review_opportunities', 'Posoudit nové nabídky v JobRadaru',
                    'Otevři přehled nerozhodnutých nabídek v JobRadaru a rozhodni, které stojí za reakci. Dokončení tohoto úkolu nemění rozhodnutí nabídek ani neodesílá žádost.', origin: 'system');
                $this->auditLogger->record('search.review_action_ensured', $userId, ['search_run_id' => $runId, 'action_item_id' => $id]);
            }
            $this->database->query('UPDATE search_runs SET review_action_processed_at = ? WHERE id = ?', self::now(), $runId);
        });
    }

    /** @return list<array<string, mixed>> */
    public function listForOpportunity(int $userId, int $opportunityId): array
    {
        return array_map($this->map(...), $this->database->fetchAll(
            'SELECT item.*, task.provider, task.external_id, task.external_url, task.last_synced_at
             FROM action_items item
             LEFT JOIN external_tasks task ON task.action_item_id = item.id
             WHERE item.user_id = ? AND item.opportunity_id = ?
             ORDER BY item.status = ? DESC, item.due_at IS NULL, item.due_at, item.id DESC',
            $userId,
            $opportunityId,
            'open',
        ));
    }

    /** @return list<array<string, mixed>> */
    public function listOpen(int $userId, bool $onlyWithoutExternalTask = false): array
    {
        $rows = $this->database->fetchAll(
            'SELECT item.*, task.provider, task.external_id, task.external_url, task.last_synced_at,
                    COALESCE(version.translated_title, version.original_title) AS opportunity_title,
                    opportunity.canonical_url AS opportunity_url
             FROM action_items item
             LEFT JOIN external_tasks task ON task.action_item_id = item.id
             LEFT JOIN opportunities opportunity ON opportunity.id = item.opportunity_id
             LEFT JOIN source_versions version ON version.id = opportunity.current_source_version_id
             WHERE item.user_id = ? AND item.status = ? AND (? = 0 OR task.id IS NULL)
             ORDER BY item.due_at IS NULL, item.due_at, item.id',
            $userId,
            'open',
            $onlyWithoutExternalTask,
        );
        return array_map($this->map(...), $rows);
    }

    public function linkExternalTask(int $userId, int $actionItemId, string $provider, string $externalId, ?string $externalUrl): void
    {
        $externalId = trim($externalId);
        $externalUrl = self::nullable($externalUrl);
        if ($provider !== 'todoist' || $externalId === '' || mb_strlen($externalId) > 255) {
            throw new \InvalidArgumentException('Identifikace externího úkolu není platná.');
        }
        if ($externalUrl !== null) {
            $parts = parse_url($externalUrl);
            if (filter_var($externalUrl, FILTER_VALIDATE_URL) === false || !is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || !in_array(strtolower((string) ($parts['host'] ?? '')), ['app.todoist.com', 'todoist.com'], true)
                || isset($parts['user']) || isset($parts['pass'])
            ) {
                throw new \InvalidArgumentException('URL externího úkolu musí být bezpečný HTTPS odkaz na Todoist.');
            }
        }

        $this->database->transaction(function () use ($userId, $actionItemId, $provider, $externalId, $externalUrl): void {
            $item = $this->ownedForUpdate($userId, $actionItemId);
            if ((string) $item['status'] !== 'open') {
                throw new \InvalidArgumentException('Externí úkol lze připojit jen k otevřenému navazujícímu kroku.');
            }
            $existing = $this->database->fetch('SELECT * FROM external_tasks WHERE action_item_id = ? AND provider = ? FOR UPDATE', $actionItemId, $provider);
            if ($existing instanceof Row && (string) $existing['external_id'] !== $externalId) {
                throw new \InvalidArgumentException('Navazující krok už je propojen s jiným externím úkolem.');
            }
            $duplicate = $this->database->fetch('SELECT action_item_id FROM external_tasks WHERE provider = ? AND external_id = ? FOR UPDATE', $provider, $externalId);
            if ($duplicate instanceof Row && (int) $duplicate['action_item_id'] !== $actionItemId) {
                throw new \InvalidArgumentException('Externí úkol už je propojen s jiným navazujícím krokem.');
            }
            $now = self::now();
            if ($existing instanceof Row) {
                $this->database->query('UPDATE external_tasks SET', ['external_url' => $externalUrl, 'last_synced_at' => $now], 'WHERE id = ?', (int) $existing['id']);
            } else {
                $this->database->query('INSERT INTO external_tasks', [
                    'action_item_id' => $actionItemId,
                    'provider' => $provider,
                    'external_id' => $externalId,
                    'external_url' => $externalUrl,
                    'last_synced_at' => $now,
                    'created_at' => $now,
                ]);
            }
            $this->auditLogger->record('action_item.external_task_linked', $userId, [
                'action_item_id' => $actionItemId,
                'provider' => $provider,
                'external_id' => $externalId,
            ]);
        });
    }

    public function complete(int $userId, int $actionItemId, string $origin = 'user'): void
    {
        $this->changeStatus($userId, $actionItemId, 'completed', $origin);
    }

    /** @return list<array<string,mixed>> */
    public function listLinked(int $userId): array
    {
        return array_map($this->map(...), $this->database->fetchAll(
            "SELECT item.*, task.provider, task.external_id, task.external_url, task.last_synced_at, task.synced_status
             FROM action_items item JOIN external_tasks task ON task.action_item_id=item.id
             WHERE item.user_id=? AND task.provider='todoist' AND (item.status='open' OR item.status<>task.synced_status) ORDER BY item.id", $userId));
    }

    public function acknowledgeExternalStatus(int $userId, int $id, string $status): void
    {
        if (!in_array($status, ['open', 'completed', 'cancelled'], true)) { throw new \InvalidArgumentException('Neplatný stav úkolu.'); }
        $this->database->transaction(function () use ($userId, $id, $status): void {
            $item = $this->ownedForUpdate($userId, $id);
            if ($item['status'] !== $status || !$this->database->fetchField("SELECT id FROM external_tasks WHERE action_item_id=? AND provider='todoist'", $id)) {
                throw new \InvalidArgumentException('Stav se změnil nebo chybí externí vazba.');
            }
            $this->database->query('UPDATE external_tasks SET', ['synced_status' => $status, 'last_synced_at' => self::now()], "WHERE action_item_id=? AND provider='todoist'", $id);
        });
    }

    public function cancel(int $userId, int $actionItemId): void
    {
        $this->changeStatus($userId, $actionItemId, 'cancelled', 'user');
    }

    private function changeStatus(int $userId, int $actionItemId, string $status, string $origin): void
    {
        if (!in_array($origin, ['user', 'assistant', 'system'], true)) {
            throw new \InvalidArgumentException('Původ změny není platný.');
        }
        $this->database->transaction(function () use ($userId, $actionItemId, $status, $origin): void {
            $item = $this->ownedForUpdate($userId, $actionItemId);
            if ((string) $item['status'] === $status) {
                return;
            }
            if ((string) $item['status'] !== 'open') {
                throw new \InvalidArgumentException('Uzavřený navazující krok už nelze změnit.');
            }
            $now = self::now();
            $this->database->query('UPDATE action_items SET', [
                'status' => $status,
                'completed_at' => $status === 'completed' ? $now : null,
                'cancelled_at' => $status === 'cancelled' ? $now : null,
                'updated_at' => $now,
            ], 'WHERE id = ?', $actionItemId);
            $this->auditLogger->record('action_item.' . $status, $userId, [
                'action_item_id' => $actionItemId,
                'origin' => $origin,
                'decision_changed' => false,
                'application_submitted' => false,
            ]);
        });
    }

    private function ownedForUpdate(int $userId, int $actionItemId): Row
    {
        $row = $this->database->fetch('SELECT * FROM action_items WHERE id = ? AND user_id = ? FOR UPDATE', $actionItemId, $userId);
        if (!$row instanceof Row) {
            throw new \InvalidArgumentException('Navazující úkol nebyl nalezen.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function map(Row $row): array
    {
        $values = (array) $row;
        $values['action_url'] = $values['action_type'] === 'review_opportunities'
            ? rtrim($this->appUrl, '/') . '/?decision=undecided'
            : ($values['opportunity_id'] === null ? null : rtrim($this->appUrl, '/') . '/nabidky/' . $values['opportunity_id']);
        if ($values['action_type'] === 'prepare_applications') {
            $values['action_url'] = rtrim($this->appUrl, '/') . '/nabidky/k-reakci';
        }
        $values['id'] = (int) $values['id'];
        $values['user_id'] = (int) $values['user_id'];
        $values['opportunity_id'] = $values['opportunity_id'] === null ? null : (int) $values['opportunity_id'];
        foreach (['due_at', 'created_at', 'completed_at', 'cancelled_at', 'updated_at', 'last_synced_at'] as $field) {
            if (($values[$field] ?? null) instanceof \DateTimeInterface) {
                $values[$field] = $values[$field]->format(DATE_ATOM);
            }
        }
        return $values;
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
