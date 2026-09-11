<?php

declare(strict_types=1);

namespace App\Search;

use Nette\Database\Connection;

/** Called within ExecutionService's lease-checked, source-locked transaction. */
final class SearchStepService
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function requestPlans(int $owner, int $request): array
    {
        $rows = $this->db->fetchAll('SELECT s.name,d.version,d.result_limit,d.steps_json,rs.id AS run_source_id FROM search_requests q JOIN search_request_sources qs ON qs.search_request_id=q.id JOIN sources s ON s.id=qs.source_id JOIN source_search_definitions d ON d.id=qs.search_definition_id LEFT JOIN search_runs r ON r.search_request_id=q.id LEFT JOIN search_run_sources rs ON rs.search_run_id=r.id AND rs.source_id=s.id WHERE q.id=? AND q.requested_by_user_id=? AND d.steps_json IS NOT NULL ORDER BY s.priority,s.name,s.id', $request, $owner);
        return array_map(function ($row): array {
            $plan = $row['run_source_id'] === null ? ['limit' => (int) $row['result_limit'], 'steps' => array_map(static fn ($step): array => [...$step, 'status' => 'planned', 'displayed_count' => 0, 'checkpoint' => null], SearchPlan::parse((string) $row['steps_json'], (int) $row['result_limit']))] : $this->state((int) $row['run_source_id']);
            return ['name' => $row['name'], 'version' => $row['version'], ...$plan];
        }, $rows);
    }

    /** @return array{limit:int,steps:list<array<string,mixed>>} */
    public function state(int $runSource): array
    {
        $d = $this->db->fetch('SELECT d.steps_json,d.result_limit FROM search_run_sources rs JOIN search_runs r ON r.id=rs.search_run_id JOIN search_request_sources qs ON qs.search_request_id=r.search_request_id AND qs.source_id=rs.source_id LEFT JOIN source_search_definitions d ON d.id=qs.search_definition_id WHERE rs.id=?', $runSource);
        if ($d === null || $d['steps_json'] === null) { return ['limit' => 0, 'steps' => []]; }
        $steps = SearchPlan::parse((string) $d['steps_json'], (int) $d['result_limit']);
        $states = [];
        foreach ($steps as $step) {
            $row = $this->db->fetch('SELECT id,step_status,displayed_count,checkpoint_json FROM search_run_steps WHERE search_run_source_id=? AND step_key=?', $runSource, $step['key']);
            $states[] = [...$step, 'status' => $row['step_status'] ?? 'planned', 'displayed_count' => (int) ($row['displayed_count'] ?? 0), 'checkpoint' => $row === null ? null : json_decode((string) ($row['checkpoint_json'] ?? 'null'), true)];
        }
        return ['limit' => (int) $d['result_limit'], 'steps' => $states];
    }

    /** @param array<string,mixed> $payload */
    public function checkpoint(int $runSource, array $payload): void
    {
        $plan = $this->state($runSource);
        if ($plan['steps'] === []) {
            if (isset($payload['step_key']) || isset($payload['step_status']) || isset($payload['step_displayed_count']) || isset($payload['step_related_count']) || isset($payload['step_completion_reason'])) { throw new \InvalidArgumentException('Původní zadání nemá vyhledávací kroky.'); }
            return;
        }
        $current = null;
        foreach ($plan['steps'] as $step) {
            if (!in_array($step['status'], ['complete','skipped_limit'], true)) { $current = $step; break; }
        }
        $status = $payload['step_status'] ?? null;
        $count = $payload['step_displayed_count'] ?? null;
        if ($current === null || ($payload['step_key'] ?? null) !== $current['key'] || !in_array($status, ['running','complete'], true)
            || !is_int($count) || $count < $current['displayed_count'] || $count > $current['limit']) {
            throw new \InvalidArgumentException('Krok není aktuální nebo má neplatný stav či kumulativní počet výsledků.');
        }
        $total = array_sum(array_column($plan['steps'], 'displayed_count')) - $current['displayed_count'] + $count;
        if ($total > $plan['limit']) { throw new \InvalidArgumentException('Překročen společný limit zdroje.'); }
        $imports = (int) $this->db->fetchField('SELECT COUNT(*) FROM search_step_opportunities so JOIN search_run_steps st ON st.id=so.step_id WHERE st.search_run_source_id=? AND st.step_key=?', $runSource, $current['key']);
        if ($current['mode'] === 'category') {
            $related = $payload['step_related_count'] ?? null;
            $previous = $current['checkpoint']['step_related_count'] ?? 0;
            if (!is_int($related) || $related < max($previous, $imports) || $related > $plan['limit']) {
                throw new \InvalidArgumentException('Kategorie vyžaduje samostatný kumulativní počet souvisejících výsledků v limitu zdroje.');
            }
        } elseif ($count < $imports || isset($payload['step_related_count'])) {
            throw new \InvalidArgumentException('Počet výsledků neodpovídá importům nebo režimu kroku.');
        }
        $reason = $payload['step_completion_reason'] ?? null;
        if ($status === 'complete' && !($reason === 'end_of_results' || ($reason === 'step_limit' && $count === $current['limit']) || ($reason === 'source_limit' && $total === $plan['limit']))) {
            throw new \InvalidArgumentException('Dokončení kroku vyžaduje dosažený limit nebo doložený konec výsledků.');
        }
        if ($status === 'running' && $reason !== null) { throw new \InvalidArgumentException('Rozpracovaný krok nemá důvod dokončení.'); }
        $this->db->query('INSERT INTO search_run_steps', ['search_run_source_id' => $runSource, 'step_key' => $current['key'], 'step_status' => $status, 'displayed_count' => $count, 'checkpoint_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => new \DateTimeImmutable()], 'ON DUPLICATE KEY UPDATE step_status=VALUES(step_status),displayed_count=VALUES(displayed_count),checkpoint_json=VALUES(checkpoint_json),updated_at=VALUES(updated_at)');
        if ($status === 'complete' && $total === $plan['limit']) {
            foreach ($plan['steps'] as $step) {
                if ($step['status'] === 'planned' && $step['key'] !== $current['key']) {
                    $this->db->query('INSERT INTO search_run_steps', ['search_run_source_id' => $runSource, 'step_key' => $step['key'], 'step_status' => 'skipped_limit', 'displayed_count' => 0, 'updated_at' => new \DateTimeImmutable()]);
                }
            }
        }
    }

    public function assertFinished(int $runSource, mixed $displayedCount): void
    {
        $plan = $this->state($runSource);
        if ($plan['steps'] === []) { return; }
        foreach ($plan['steps'] as $step) {
            if (!in_array($step['status'], ['complete','skipped_limit'], true)) { throw new \InvalidArgumentException('Zbývají nedokončené kroky hledání.'); }
        }
        if ($displayedCount !== array_sum(array_column($plan['steps'], 'displayed_count'))) { throw new \InvalidArgumentException('Souhrnný počet neodpovídá krokům.'); }
    }

    public function importStep(int $runSource, mixed $key): ?int
    {
        $plan = $this->state($runSource);
        if ($plan['steps'] === []) {
            if ($key !== null) { throw new \InvalidArgumentException('Původní zadání nemá kroky.'); }
            return null;
        }
        foreach ($plan['steps'] as $step) {
            if ($step['key'] === $key && $step['status'] === 'running') {
                return (int) $this->db->fetchField('SELECT id FROM search_run_steps WHERE search_run_source_id=? AND step_key=?', $runSource, $key);
            }
        }
        throw new \InvalidArgumentException('Import vyžaduje zahájený aktuální searchStep.');
    }

    public function linkImport(int $runSource, int $stepId, int $opportunity): void
    {
        $this->db->query('INSERT IGNORE INTO search_step_opportunities', ['step_id' => $stepId, 'opportunity_id' => $opportunity]);
        $plan = $this->state($runSource);
        $consumed = 0;
        foreach ($plan['steps'] as $step) {
            $imported = (int) $this->db->fetchField('SELECT COUNT(*) FROM search_step_opportunities so JOIN search_run_steps st ON st.id=so.step_id WHERE st.search_run_source_id=? AND st.step_key=?', $runSource, $step['key']);
            if ($step['mode'] === 'category') {
                if ($imported > $plan['limit']) { throw new \InvalidArgumentException('Překročen limit souvisejících detailů kategorie.'); }
                $consumed += $step['displayed_count'];
            } else {
                if ($imported > $step['limit']) { throw new \InvalidArgumentException('Překročen limit importů kroku.'); }
                $consumed += max($step['displayed_count'], $imported);
            }
        }
        if ($consumed > $plan['limit']) { throw new \InvalidArgumentException('Překročen společný limit importů zdroje.'); }
    }
}
