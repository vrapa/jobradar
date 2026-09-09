<?php

declare(strict_types=1);

namespace App\Search;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;

final class AccessPreparationService
{
    public function __construct(private readonly Connection $db, private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function status(int $owner, int $request): array
    {
        $row = $this->db->fetch('SELECT prepare_access,access_confirmed_at,request_status FROM search_requests WHERE id = ? AND requested_by_user_id = ?', $request, $owner);
        if ($row === null) { throw new \InvalidArgumentException('Požadavek nenalezen.'); }
        return [...(array) $row, 'sources' => array_map(static fn ($r): array => (array) $r, $this->db->fetchAll('SELECT s.id,s.name,s.url,p.access_status,p.observed_at FROM search_request_sources rs JOIN sources s ON s.id = rs.source_id LEFT JOIN search_access_preparations p ON p.source_id = s.id AND p.search_request_id = rs.search_request_id WHERE rs.search_request_id = ? ORDER BY s.priority,s.name,s.id', $request))];
    }

    public function confirm(int $owner, int $request): void
    {
        $this->db->transaction(function () use ($owner, $request): void {
            $row = $this->db->fetch('SELECT * FROM search_requests WHERE id = ? AND requested_by_user_id = ? FOR UPDATE', $request, $owner);
            if ($row === null || !(bool) $row['prepare_access']) { throw new \InvalidArgumentException('Příprava přístupu nebyla vyžádána.'); }
            if ($row['access_confirmed_at'] !== null) { return; }
            if ($row['request_status'] !== 'waiting_for_login') { throw new \InvalidArgumentException('Počkejte na přípravu přístupů vykonavatelem.'); }
            if ($this->db->fetchField('SELECT COUNT(*) FROM search_request_sources rs LEFT JOIN search_access_preparations p ON p.search_request_id = rs.search_request_id AND p.source_id = rs.source_id WHERE rs.search_request_id = ? AND p.source_id IS NULL', $request)) { throw new \InvalidArgumentException('Příprava zdrojů ještě není dokončena.'); }
            $this->db->query("UPDATE search_requests SET access_confirmed_at = ?, request_status = 'resume_requested', lease_token_hash = NULL, lease_expires_at = NULL WHERE id = ?", new \DateTimeImmutable(), $request);
            $this->audit->record('search.access_confirmed', $owner, ['search_request_id' => $request]);
        });
    }
}
