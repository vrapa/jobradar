<?php

declare(strict_types=1);

namespace App\Search;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;

final class SourceSettingsService
{
    public function __construct(private readonly Connection $db, private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $row = $this->db->fetch('SELECT * FROM sources WHERE id = ? AND active = 1 AND archived_at IS NULL', $id);
        if ($row === null) { throw new \InvalidArgumentException('Zdroj není dostupný.'); }
        return (array) $row;
    }

    /** @return list<array<string, mixed>> */
    public function definitions(int $sourceId): array
    {
        return array_map(static fn ($row): array => (array) $row, $this->db->fetchAll(
            'SELECT d.* FROM source_search_definitions d WHERE d.source_id = ? AND NOT EXISTS
            (SELECT 1 FROM source_search_definitions n WHERE n.source_id = d.source_id AND n.name = d.name AND n.version > d.version) ORDER BY d.name, d.id', $sourceId));
    }

    /** @return list<array<string, mixed>> */
    public function manualSources(): array
    {
        return array_map(function ($row): array {
            return [...(array) $row, 'definitions' => array_values(array_filter($this->definitions((int) $row['id']), static fn (array $d): bool => (bool) $d['active']))];
        }, $this->db->fetchAll("SELECT * FROM sources WHERE active = 1 AND archived_at IS NULL AND source_type = 'manual_search' ORDER BY priority,name,id"));
    }

    /** @return array<string, mixed> */
    public function discoveryDefinition(int $id): array
    {
        $row = $this->db->fetch("SELECT d.name,d.version,d.query_text,s.name AS source_name FROM source_search_definitions d JOIN sources s ON s.id=d.source_id WHERE d.id=? AND s.active=1 AND s.archived_at IS NULL AND s.source_type='manual_search'", $id);
        if ($row === null) { throw new \InvalidArgumentException('Dotaz ručního vyhledávání nebyl nalezen.'); }
        return (array) $row;
    }

    public function save(int $actor, int $id, int $version, string $priority, ?string $name = null, ?string $query = null, bool $active = true): void
    {
        $this->db->transaction(function () use ($actor, $id, $version, $priority, $name, $query, $active): void {
            if (!$this->db->fetchField("SELECT id FROM users WHERE id = ? AND role = 'admin' AND deactivated_at IS NULL", $actor)) {
                throw new \InvalidArgumentException('Změny zdrojů smí provádět pouze administrátor.');
            }
            $source = $this->db->fetch('SELECT * FROM sources WHERE id = ? FOR UPDATE', $id);
            if ($source === null || !(bool) $source['active'] || $source['archived_at'] !== null || (int) $source['lock_version'] !== $version) {
                throw new \InvalidArgumentException('Zdroj se změnil nebo není dostupný. Obnovte stránku a změnu zopakujte.');
            }
            if (!in_array($priority, ['A','B','C'], true)) { throw new \InvalidArgumentException('Neplatná priorita.'); }
            if ($name !== null && trim($name) !== '') {
                if ($source['source_type'] !== 'manual_search' || mb_strlen($name) > 255 || trim((string) $query) === '' || mb_strlen((string) $query) > 2000) {
                    throw new \InvalidArgumentException('Neplatný ruční dotaz.');
                }
                $name = trim($name);
                $next = 1 + (int) $this->db->fetchField('SELECT MAX(version) FROM source_search_definitions WHERE source_id = ? AND name = ?', $id, $name);
                $this->db->query('UPDATE source_search_definitions SET active = 0 WHERE source_id = ? AND name = ?', $id, $name);
                $this->db->query('INSERT INTO source_search_definitions', ['source_id' => $id, 'name' => $name, 'version' => $next, 'query_text' => trim((string) $query), 'active' => $active, 'created_at' => new \DateTimeImmutable()]);
                $this->audit->record('source.query_version_created', $actor, ['source_id' => $id, 'definition_name' => $name, 'version' => $next, 'active' => $active]);
            }
            $this->db->query('UPDATE sources SET priority = ?, lock_version = lock_version + 1, updated_at = ? WHERE id = ?', $priority, new \DateTimeImmutable(), $id);
            $this->audit->record('source.priority_saved', $actor, ['source_id' => $id, 'old_priority' => (string) $source['priority'], 'new_priority' => $priority]);
        });
    }
}
