<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;

final class ProjectCareService
{
    public function __construct(private readonly Connection $db, private readonly AuditLogger $audit) {}

    /** @return list<array<string, mixed>> */
    public function history(int $id): array
    {
        return array_map(static function ($r): array {
            $v = (array) $r;
            $v['value'] = $v['value'] === null ? null : (bool) $v['value'];
            $v['confidence'] = $v['confidence'] === null ? null : (float) $v['confidence'];
            $v['verified_at'] = $v['verified_at']?->format(DATE_ATOM);
            $v['created_at'] = $v['created_at']->format(DATE_ATOM);
            return $v;
        }, $this->db->fetchAll('SELECT * FROM opportunity_project_care WHERE opportunity_id = ? ORDER BY id DESC', $id));
    }

    public function save(int $id, int $expectedVersion, ProjectCareInput $input, ?int $actor, ?int $sourceVersion = null): void
    {
        $this->db->transaction(function () use ($id, $expectedVersion, $input, $actor, $sourceVersion): void {
            $row = $this->db->fetch('SELECT current_source_version_id,lock_version FROM opportunities WHERE id = ? AND archived_at IS NULL FOR UPDATE', $id);
            if ($row === null || (int) $row['lock_version'] !== $expectedVersion) { throw new OpportunityConflictException('Nabídka se změnila. Obnovte stránku.'); }
            $sourceVersion ??= (int) $row['current_source_version_id'];
            if (!$this->db->fetchField('SELECT id FROM source_versions WHERE id = ? AND opportunity_id = ?', $sourceVersion, $id)) { throw new \InvalidArgumentException('Verze nepatří nabídce.'); }
            $previous = $this->history($id)[0] ?? null;
            if ($previous !== null && (int) $previous['source_version_id'] === $sourceVersion && $previous['value'] === $input->value && $previous['reason'] === $input->reason && $previous['confidence'] === $input->confidence && $previous['verified_at'] === $input->verifiedAt?->format(DATE_ATOM)) { return; }
            $this->db->query('INSERT INTO opportunity_project_care', ['opportunity_id' => $id, 'source_version_id' => $sourceVersion, 'value' => $input->value, 'reason' => $input->reason, 'confidence' => $input->confidence, 'verified_at' => $input->verifiedAt, 'actor_user_id' => $actor, 'created_at' => new \DateTimeImmutable()]);
            $this->db->query('UPDATE opportunities SET lock_version = lock_version + 1, updated_at = ? WHERE id = ?', new \DateTimeImmutable(), $id);
            $this->audit->record('opportunity.project_care_classified', $actor, ['opportunity_id' => $id, 'source_version_id' => $sourceVersion, 'value' => $input->value]);
        });
    }
}
