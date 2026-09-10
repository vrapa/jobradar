<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Action\ActionItemService;
use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;

final class AttachmentReviewService
{
    public function __construct(private readonly Connection $db, private readonly ActionItemService $actions, private readonly AuditLogger $audit) {}

    /** @param list<array<string,mixed>> $items */
    public function save(int $userId, int $opportunityId, int $versionId, array $items): void
    {
        $items = AttachmentReviewInput::parse($items);
        $this->db->transaction(function () use ($userId, $opportunityId, $versionId, $items): void {
            // Serialize first insertion too, so two imports cannot create two follow-up tasks.
            if (!$this->db->fetchField('SELECT id FROM opportunities WHERE id=? AND archived_at IS NULL FOR UPDATE', $opportunityId)
                || !$this->db->fetchField('SELECT id FROM source_versions WHERE id=? AND opportunity_id=?', $versionId, $opportunityId)) {
                throw new \InvalidArgumentException('Verze nepatří nabídce.');
            }
            foreach ($items as $item) {
                $old = $this->db->fetch('SELECT * FROM attachment_reviews WHERE user_id=? AND opportunity_id=? AND attachment_key=? FOR UPDATE', $userId, $opportunityId, $item['key']);
                $values = ['name' => $item['name'], 'status' => $item['status'], 'reason' => $item['reason'], 'questions' => $item['questions'], 'findings' => $item['findings'], 'evidence' => $item['evidence']];
                if ($old !== null) {
                    $same = true;
                    foreach ($values as $key => $value) { if ($old[$key] !== $value) { $same = false; } }
                    if ($same) { continue; }
                    // A later routine traversal must not reopen already resolved evidence.
                    if ($item['status'] === 'pending' && !isset($item['expectedRevision'])) { continue; }
                    if (($item['expectedRevision'] ?? null) !== (int) $old['revision']) { throw new \InvalidArgumentException('Příloha se změnila. Načtěte aktuální revizi.'); }
                }
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $values += ['source_version_id' => $versionId, 'observed_at' => (new \DateTimeImmutable($item['observedAt']))->setTimezone(new \DateTimeZone('UTC')), 'updated_at' => $now, 'revision' => $old === null ? 1 : (int) $old['revision'] + 1];
                $actionId = $old === null ? null : $old['action_item_id'];
                if ($item['status'] === 'pending' && ($actionId === null || ($old !== null && $old['status'] !== 'pending'))) {
                    $actionId = $this->actions->create($userId, $opportunityId, 'verify_attachment', mb_substr('Prověřit přílohu – ' . $item['name'], 0, 255),
                        "Vyřešit v projektu Práce.\nDůvod: " . $item['reason'] . "\nCo zjistit: " . $item['questions'] . "\nPo přečtení zapsat zjištění a nové posouzení do JobRadaru. Samotné stažení či odškrtnutí úkolu neověřuje přílohu.", origin: 'assistant');
                } elseif ($item['status'] !== 'pending' && $actionId !== null && $this->db->fetchField('SELECT status FROM action_items WHERE id=?', $actionId) === 'open') {
                    $this->actions->complete($userId, (int) $actionId, 'assistant');
                }
                $values['action_item_id'] = $actionId;
                if ($old === null) {
                    $this->db->query('INSERT INTO attachment_reviews', $values + ['user_id' => $userId, 'opportunity_id' => $opportunityId, 'attachment_key' => $item['key']]);
                    $id = (int) $this->db->getInsertId();
                } else {
                    $id = (int) $old['id'];
                    $this->db->query('UPDATE attachment_reviews SET', $values, 'WHERE id=?', $id);
                }
                $this->audit->record('attachment_review.saved', $userId, ['attachment_review_id' => $id, 'opportunity_id' => $opportunityId, 'previous_json' => $old === null ? null : json_encode((array) $old, JSON_THROW_ON_ERROR), 'result_json' => json_encode($values, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function listForOpportunity(int $userId, int $opportunityId): array
    {
        return array_map(static function ($row): array {
            $data = (array) $row;
            foreach (['observed_at', 'updated_at'] as $field) { $data[$field] = $data[$field]->format(DATE_ATOM); }
            return $data;
        }, $this->db->fetchAll('SELECT * FROM attachment_reviews WHERE user_id=? AND opportunity_id=? ORDER BY id', $userId, $opportunityId));
    }
}
