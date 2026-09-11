<?php

declare(strict_types=1);

namespace App\Search;

use App\Api\Auth\ApiIdentity;
use App\Api\V1\RecordSourceProgressHandler;
use App\Assessment\AssessmentPayloadMapper;
use App\Assessment\AssessmentService;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityJsonMapper;
use Nette\Database\Connection;
use Nette\Database\Row;

/** The executor can only mutate sources in its owner's currently leased run. */
final class ExecutionService
{
    public function __construct(
        private readonly Connection $database,
        private readonly RunnerDeviceService $devices,
        private readonly RunnerLeaseService $leases,
        private readonly RecordSourceProgressHandler $progress,
        private readonly OpportunityJsonMapper $imports,
        private readonly OpportunityImportService $importer,
        private readonly AssessmentPayloadMapper $assessmentMapper,
        private readonly AssessmentService $assessments,
        private readonly SearchStepService $steps,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function execute(ApiIdentity $identity, array $body): array
    {
        if (!$identity->hasScope('search:execute')) {
            throw new \InvalidArgumentException('Chybí oprávnění vykonavatele.');
        }
        $device = $this->devices->requireDeviceId($identity);
        $operation = $body['operation'] ?? '';
        if (array_diff(array_keys($body), ['operation', 'run_id', 'source_id', 'lease_token', 'idempotency_key', 'payload']) !== []) {
            throw new \InvalidArgumentException('Neznámá pole vykonávacího požadavku.');
        }
        if ($operation === 'status') {
            $this->database->query('UPDATE runner_devices SET last_seen_at = ?, device_status = ? WHERE id = ?', new \DateTimeImmutable(), 'online', $device);
            return ['device_id' => $device, 'protocol' => 1];
        }
        if ($operation === 'claim') {
            $lease = $this->leases->claimNext($device, $identity->ownerUserId);
            return ['lease' => $lease === null ? null : [
                'request_id' => $lease->requestId, 'run_id' => $lease->runId, 'lease_token' => $lease->token,
                'lease_expires_at' => $lease->expiresAt->format(DATE_ATOM), 'source_ids' => $lease->sourceIds,
            ]];
        }
        if (!is_int($body['run_id'] ?? null) || !is_string($body['lease_token'] ?? null)) {
            throw new \InvalidArgumentException('Chybí běh a lease.');
        }
        /** @var array<string, mixed> */
        return $this->database->transaction(function () use ($identity, $device, $operation, $body): array {
            $run = $this->database->fetch(
                'SELECT r.*, q.requested_by_user_id, q.lease_token_hash, q.lease_expires_at, q.request_status, q.prepare_access, q.access_confirmed_at FROM search_requests q
                 JOIN search_runs r ON r.search_request_id = q.id WHERE r.id = ? AND q.runner_device_id = ? AND q.requested_by_user_id = ? FOR UPDATE',
                $body['run_id'], $device, $identity->ownerUserId,
            );
            if (!$run instanceof Row) {
                throw new \InvalidArgumentException('Běh není přidělen tomuto vykonavateli.');
            }
            $valid = $run['lease_token_hash'] !== null
                && hash_equals((string) $run['lease_token_hash'], hash('sha256', $body['lease_token']))
                && $run['lease_expires_at'] instanceof \DateTimeInterface && $run['lease_expires_at'] > new \DateTimeImmutable();
            if (!$valid && in_array($operation, ['renew', 'verify', 'task'], true)) {
                throw new \InvalidArgumentException('Lease vypršel nebo byl odvolán.');
            }
            if ($operation === 'renew') {
                return ['expires_at' => $this->leases->renew($device, (int) $run['search_request_id'], $body['lease_token'])->format(DATE_ATOM)];
            }
            if ($operation === 'verify') {
                return ['lease_valid' => true, 'run_id' => (int) $run['id'], 'expires_at' => $run['lease_expires_at']->format(DATE_ATOM)];
            }
            if ($operation === 'task') {
                return $this->task($run, $identity);
            }
            $source = $this->database->fetch('SELECT * FROM search_run_sources WHERE search_run_id = ? AND source_id = ? FOR UPDATE', $run['id'], $body['source_id'] ?? 0);
            if (!$source instanceof Row) {
                throw new \InvalidArgumentException('Zdroj není přidělen tomuto běhu.');
            }
            $key = $body['idempotency_key'] ?? null;
            $payload = $body['payload'] ?? [];
            if (!is_string($key) || strlen($key) < 16 || strlen($key) > 200 || !is_array($payload)) {
                throw new \InvalidArgumentException('Neplatný idempotency klíč nebo payload.');
            }
            $hash = hash('sha256', json_encode([$operation, $payload], JSON_THROW_ON_ERROR));
            $existing = $this->database->fetch('SELECT * FROM execution_events WHERE search_run_source_id = ? AND key_hash = ?', $source['id'], hash('sha256', $key));
            if ($existing instanceof Row) {
                if ($existing['payload_hash'] !== $hash || !hash_equals((string) $existing['lease_hash'], hash('sha256', $body['lease_token']))) {
                    throw new \InvalidArgumentException('Idempotency klíč již označuje jiná data.');
                }
                /** @var array<string, mixed> $replayed */
                $replayed = json_decode((string) $existing['response_json'], true, 64, JSON_THROW_ON_ERROR);
                return $replayed;
            }
            if (!$valid) {
                throw new \InvalidArgumentException('Lease vypršel nebo byl odvolán.');
            }
            if (!in_array($operation, ['prepare_access', 'start', 'finish', 'checkpoint', 'pause', 'import', 'assessment'], true)) {
                throw new \InvalidArgumentException('Neznámá vykonávací operace.');
            }
            $preparing = (bool) $run['prepare_access'] && $run['access_confirmed_at'] === null;
            if ($preparing !== ($operation === 'prepare_access')) { throw new \InvalidArgumentException('Nejprve dokončete přípravu přístupu a vyčkejte na potvrzení uživatele.'); }
            if ($operation === 'prepare_access') {
                $access = $payload['status'] ?? null;
                if (array_diff(array_keys($payload), ['status']) !== [] || !in_array($access, ['available','login_required','blocked','error'], true)) { throw new \InvalidArgumentException('Neplatný výsledek přípravy.'); }
                $observedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $this->database->query('INSERT INTO search_access_preparations', ['search_request_id' => $run['search_request_id'], 'source_id' => $source['source_id'], 'access_status' => $access, 'observed_at' => $observedAt], 'ON DUPLICATE KEY UPDATE access_status = VALUES(access_status), observed_at = VALUES(observed_at)');
                $this->database->query('INSERT INTO source_access_states', [
                    'source_id' => $source['source_id'],
                    'access_status' => $access,
                    'verified_at' => $observedAt,
                    'verification_origin' => 'runner:' . $device,
                    'intervention_required' => $access === 'login_required',
                    'updated_at' => $observedAt,
                ], 'ON DUPLICATE KEY UPDATE access_status = VALUES(access_status), verified_at = VALUES(verified_at), verification_origin = VALUES(verification_origin), intervention_required = VALUES(intervention_required), updated_at = VALUES(updated_at)');
                $remaining = $this->database->fetchField('SELECT COUNT(*) FROM search_request_sources rs LEFT JOIN search_access_preparations p ON p.search_request_id = rs.search_request_id AND p.source_id = rs.source_id WHERE rs.search_request_id = ? AND p.source_id IS NULL', $run['search_request_id']);
                if ((int) $remaining === 0) {
                    $this->database->query("UPDATE search_requests SET request_status = 'waiting_for_login', lease_token_hash = NULL, lease_expires_at = NULL WHERE id = ?", $run['search_request_id']);
                }
                $result = ['prepared' => true, 'request_status' => (int) $remaining === 0 ? 'waiting_for_login' : 'checking_access'];
                $this->database->query('INSERT INTO execution_events', ['search_run_source_id' => $source['id'], 'key_hash' => hash('sha256', $key), 'payload_hash' => $hash, 'response_json' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => new \DateTimeImmutable(), 'lease_hash' => hash('sha256', $body['lease_token'])]);
                return $result;
            }
            if ($operation !== 'start' && $source['source_status'] !== 'running') {
                throw new \InvalidArgumentException('Zápis vyžaduje zahájený zdroj.');
            }
            if ($operation === 'finish' && ($payload['status'] ?? '') === 'complete') {
                $this->steps->assertFinished((int) $source['id'], $payload['displayed_count'] ?? null);
                $definition = $this->database->fetchField('SELECT search_definition_id FROM search_request_sources WHERE search_request_id = ? AND source_id = ?', $run['search_request_id'], $source['source_id']);
                if ($source['checkpoint_json'] === null || $definition === null || $run['candidate_profile_id'] === null || $run['scoring_rule_set_id'] === null) {
                    throw new \InvalidArgumentException('Úplný průchod vyžaduje uložené zadání, profil, pravidla a doložený checkpoint.');
                }
            }
            $result = match ($operation) {
                'start', 'finish' => $this->recordProgress($body, $payload),
                'checkpoint' => $this->checkpoint($source, $payload),
                'pause' => $this->pause($source, $run, $payload),
                'import' => $this->import($source, $payload, $identity),
                'assessment' => $this->assessment($source, $payload, $run),
            };
            $this->database->query('INSERT INTO execution_events', [
                'search_run_source_id' => $source['id'], 'key_hash' => hash('sha256', $key), 'payload_hash' => $hash,
                'response_json' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => new \DateTimeImmutable(),
                'lease_hash' => hash('sha256', $body['lease_token']),
            ]);
            return $result;
        });
    }

    /** @return array<string, mixed> */
    private function task(Row $run, ApiIdentity $identity): array
    {
        $sources = $this->database->fetchAll(
            'SELECT s.id, s.name, s.url, rs.id AS run_source_id, rs.source_status, rs.query_text, rs.filters_json, rs.checkpoint_json, rs.checkpoint_at,
                    d.query_text AS search_query, d.filters_json AS search_filters, d.result_limit, d.pagination_strategy, d.review_guidance
             FROM search_run_sources rs JOIN sources s ON s.id = rs.source_id
             JOIN search_request_sources qs ON qs.source_id = s.id AND qs.search_request_id = ?
             LEFT JOIN source_search_definitions d ON d.id = qs.search_definition_id
             WHERE rs.search_run_id = ? AND s.source_type NOT IN (\'manual\', \'manual_search\') ORDER BY s.priority, s.name, s.id', $run['search_request_id'], $run['id'],
        );
        $assessmentSchema = \App\Mcp\JobRadarMcpServerFactory::assessmentSchema();
        $assessmentSchema['required'] = array_values(array_diff($assessmentSchema['required'], ['idempotencyKey']));
        $assessmentSchema['required'][] = 'opportunityId';
        unset($assessmentSchema['properties']['idempotencyKey']);
        $assessmentSchema['properties']['opportunityId'] = ['type' => 'integer', 'minimum' => 1];
        $opportunitySchema = \App\Mcp\JobRadarMcpServerFactory::opportunitySchema();
        $opportunitySchema['properties']['searchStep'] = ['type' => 'string', 'description' => 'Required for multi-step plans: key of the running step.'];
        return ['run_id' => (int) $run['id'], 'request_id' => (int) $run['search_request_id'],
            'prepare_access' => (bool) $run['prepare_access'] && $run['access_confirmed_at'] === null,
            'browser_session_name' => '💼 Práce',
            'access_preparations' => array_map(static fn ($r): array => (array) $r, $this->database->fetchAll('SELECT source_id,access_status FROM search_access_preparations WHERE search_request_id = ?', $run['search_request_id'])),
            'schemas' => ['opportunity' => $opportunitySchema, 'assessment' => $assessmentSchema],
            'imported_opportunities' => array_map(static fn (Row $row): array => (array) $row, $this->database->fetchAll('SELECT ro.search_run_source_id, rs.source_id, o.id, o.lock_version, o.canonical_url FROM search_run_opportunities ro JOIN search_run_sources rs ON rs.id = ro.search_run_source_id JOIN opportunities o ON o.id = ro.opportunity_id WHERE ro.search_run_id = ?', $run['id'])),
            'sources' => array_map(fn (Row $row): array => [...(array) $row, 'search_plan' => $this->steps->state((int) $row['run_source_id'])], $sources),
            'profile' => $run['candidate_profile_id'] === null ? null : (array) $this->database->fetch('SELECT id, version, description, parameters_json FROM candidate_profiles WHERE id = ? AND created_by_user_id = ?', $run['candidate_profile_id'], $identity->ownerUserId),
            'rules' => $run['scoring_rule_set_id'] === null ? null : (array) $this->database->fetch('SELECT id, status, rules_json, description FROM scoring_rule_sets WHERE id = ? AND created_by_user_id = ?', $run['scoring_rule_set_id'], $identity->ownerUserId),
            'instructions' => 'Missing search definition or profile means configuration is required. Never invent scope or a matching profile.',
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function recordProgress(array $body, array $payload): array
    {
        $response = $this->progress->handle(['id' => $body['run_id'], 'sourceId' => $body['source_id'], 'body' => [
            'event' => $body['operation'], 'lease_token' => $body['lease_token'],
            $body['operation'] === 'start' ? 'scope' : 'result' => $payload,
        ]]);
        if ($response->getCode() !== 200) {
            throw new \InvalidArgumentException('Neplatný přechod nebo výsledek zdroje.');
        }
        return ['recorded' => true, 'operation' => $body['operation'], 'request_status' => $this->database->fetchField('SELECT q.request_status FROM search_requests q JOIN search_runs r ON r.search_request_id = q.id WHERE r.id = ?', $body['run_id'])];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function checkpoint(Row $source, array $payload): array
    {
        if (array_diff(array_keys($payload), ['url', 'page', 'completed_unit', 'pages_traversed', 'detail_opened_count', 'step_key', 'step_status', 'step_displayed_count', 'step_related_count', 'step_completion_reason']) !== []
            || !is_string($payload['completed_unit'] ?? null) || trim($payload['completed_unit']) === '' || strlen($payload['completed_unit']) > 2000) {
            throw new \InvalidArgumentException('Checkpoint vyžaduje stručnou dokončenou jednotku a známá pole.');
        }
        foreach (['page', 'pages_traversed', 'detail_opened_count'] as $count) {
            if (isset($payload[$count]) && (!is_int($payload[$count]) || $payload[$count] < 0)) {
                throw new \InvalidArgumentException('Neplatný počet checkpointu.');
            }
        }
        if (isset($payload['url'])) {
            $url = is_string($payload['url']) ? parse_url($payload['url']) : false;
            if (!$url || !isset($url['host']) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass']) || strlen($payload['url']) > 2048) {
                throw new \InvalidArgumentException('Checkpoint URL není platné.');
            }
            parse_str($url['query'] ?? '', $query);
            if (isset($url['fragment']) || array_any(array_keys($query), static fn (string|int $key): bool => preg_match('/token|session|auth|code|key|password|cookie|secret|csrf|state/i', (string) $key) === 1)) {
                throw new \InvalidArgumentException('Checkpoint nesmí ukládat autentizační URL ani fragmenty.');
            }
        }
        $this->steps->checkpoint((int) $source['id'], $payload);
        $this->database->query('UPDATE search_run_sources SET checkpoint_json = ?, checkpoint_at = ? WHERE id = ?', json_encode($payload, JSON_THROW_ON_ERROR), new \DateTimeImmutable(), $source['id']);
        return ['checkpoint_saved' => true];
    }

    /** Save progress and release this lease without closing or creating a request.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pause(Row $source, Row $run, array $payload): array
    {
        $this->checkpoint($source, $payload);
        // Keep running + expired lease so the existing recovery path claims this same run.
        // The hash is retained solely for idempotent receipt retries; it is no longer valid.
        $this->database->query('UPDATE search_requests SET lease_expires_at = ? WHERE id = ?', new \DateTimeImmutable('-1 second'), $run['search_request_id']);
        return ['paused' => true, 'checkpoint_saved' => true, 'request_status' => 'running', 'run_id' => (int) $run['id']];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function import(Row $source, array $payload, ApiIdentity $identity): array
    {
        $stepId = $this->steps->importStep((int) $source['id'], $payload['searchStep'] ?? null);
        unset($payload['searchStep']);
        $imports = $this->imports->map(json_encode($payload, JSON_THROW_ON_ERROR));
        if (count($imports) !== 1) {
            throw new \InvalidArgumentException('Import vyžaduje jednu nabídku.');
        }
        $result = $this->importer->import($imports[0], $identity->ownerUserId, (int) $source['source_id']);
        if ($stepId !== null) { $this->steps->linkImport((int) $source['id'], $stepId, $result->opportunityId); }
        $this->database->query('INSERT IGNORE INTO search_run_opportunities', [
            'search_run_id' => $source['search_run_id'], 'search_run_source_id' => $source['id'], 'opportunity_id' => $result->opportunityId,
            'processing_result' => $result->opportunityCreated ? 'created' : ($result->versionCreated ? 'updated' : 'duplicate'), 'created_at' => new \DateTimeImmutable(),
        ]);
        return ['opportunity_id' => $result->opportunityId, 'source_version_id' => $result->sourceVersionId,
            'opportunity_created' => $result->opportunityCreated, 'version_created' => $result->versionCreated,
            'lock_version' => (int) $this->database->fetchField('SELECT lock_version FROM opportunities WHERE id = ?', $result->opportunityId)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function assessment(Row $source, array $payload, Row $run): array
    {
        $data = $this->assessmentMapper->map(json_encode([...$payload, 'authorType' => 'assistant'], JSON_THROW_ON_ERROR));
        if ($run['candidate_profile_id'] === null || $run['scoring_rule_set_id'] === null
            || $data->assessment->candidateProfileId !== (int) $run['candidate_profile_id']
            || $data->assessment->scoringRuleSetId !== (int) $run['scoring_rule_set_id']
            || $this->database->fetchField('SELECT opportunity_id FROM search_run_opportunities WHERE search_run_source_id = ? AND opportunity_id = ?', $source['id'], $data->opportunityId) === null) {
            throw new \InvalidArgumentException('Nabídka, profil nebo pravidla nejsou přidělené tomuto běhu.');
        }
        $result = $this->assessments->save($data->opportunityId, $data->expectedLockVersion, $data->assessment, $data->breakdowns, $data->findings);
        return ['assessment_id' => $result->assessmentId, 'decision_changed' => false, 'application_submitted' => false];
    }
}
