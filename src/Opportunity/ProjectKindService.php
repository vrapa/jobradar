<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;

final class ProjectKindService
{
    public function __construct(private readonly Connection $db, private readonly AuditLogger $audit) {}

    /** @return list<array<string, mixed>> */
    public function history(int $id): array
    {
        return array_map(static function ($row): array {
            $value = (array) $row;
            $value['values'] = $value['values_json'] === null ? null : json_decode((string) $value['values_json'], true, 32, JSON_THROW_ON_ERROR);
            unset($value['values_json']);
            $value['confidence'] = $value['confidence'] === null ? null : (float) $value['confidence'];
            $value['verified_at'] = $value['verified_at']?->format(DATE_ATOM);
            $value['created_at'] = $value['created_at']->format(DATE_ATOM);
            return $value;
        }, $this->db->fetchAll('SELECT * FROM opportunity_project_kinds WHERE opportunity_id = ? ORDER BY id DESC', $id));
    }

    public function save(int $id, int $expectedVersion, ProjectKindInput $input, ?int $actor, ?int $sourceVersion = null): void
    {
        $this->db->transaction(function () use ($id, $expectedVersion, $input, $actor, $sourceVersion): void {
            $row = $this->db->fetch('SELECT current_source_version_id,lock_version FROM opportunities WHERE id = ? AND archived_at IS NULL FOR UPDATE', $id);
            if ($row === null || (int) $row['lock_version'] !== $expectedVersion) { throw new OpportunityConflictException('Nabídka se změnila. Obnovte stránku.'); }
            $sourceVersion ??= (int) $row['current_source_version_id'];
            if (!$this->db->fetchField('SELECT id FROM source_versions WHERE id = ? AND opportunity_id = ?', $sourceVersion, $id)) { throw new \InvalidArgumentException('Verze nepatří nabídce.'); }
            $previous = $this->history($id)[0] ?? null;
            if ($previous !== null && (int) $previous['source_version_id'] === $sourceVersion && $previous['values'] === $input->values && $previous['reason'] === $input->reason && $previous['confidence'] === $input->confidence && $previous['verified_at'] === $input->verifiedAt?->format(DATE_ATOM)) { return; }
            $this->db->query('INSERT INTO opportunity_project_kinds', [
                'opportunity_id' => $id,
                'source_version_id' => $sourceVersion,
                'values_json' => $input->values === null ? null : json_encode($input->values, JSON_THROW_ON_ERROR),
                'reason' => $input->reason,
                'confidence' => $input->confidence,
                'verified_at' => $input->verifiedAt,
                'actor_user_id' => $actor,
                'created_at' => new \DateTimeImmutable(),
            ]);
            $this->db->query('UPDATE opportunities SET lock_version = lock_version + 1, updated_at = ? WHERE id = ?', new \DateTimeImmutable(), $id);
            $this->audit->record('opportunity.project_kind_classified', $actor, ['opportunity_id' => $id, 'source_version_id' => $sourceVersion, 'values_json' => $input->values === null ? null : json_encode($input->values, JSON_THROW_ON_ERROR)]);
        });
    }
}
