<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\ProjectCareInput;
use App\Opportunity\ProjectCareService;
use App\Search\SourceSettingsService;
use App\Search\SourceQueryService;
use App\Search\SearchRequestService;
use Nette\Database\Connection;
use Nette\DI\Container;
use PHPUnit\Framework\TestCase;

final class SourcePreferencesTest extends TestCase
{
    private Container $container;
    private Connection $db;
    private int $user;
    private int $source;

    protected function setUp(): void
    {
        if (getenv('DB_HOST') === false) { self::markTestSkipped('Vyžaduje izolovanou testovací DB.'); }
        $this->container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $this->db = $this->container->getByType(Connection::class);
    }

    private function seed(): void
    {
        $unique = bin2hex(random_bytes(8));
        $now = new \DateTimeImmutable();
        $this->db->query('INSERT INTO users', ['email' => $unique . '@example.test', 'display_name' => 'Preference test', 'password_hash' => 'unusable', 'role' => 'admin', 'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now]);
        $this->user = (int) $this->db->getInsertId();
        $this->db->query('INSERT INTO sources', ['name' => 'Preference test ' . $unique, 'url' => 'https://example.test/' . $unique, 'source_type' => 'browser', 'priority' => 'A', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $this->source = (int) $this->db->getInsertId();
        $this->db->query('INSERT INTO sources', ['name' => 'Synthetic manual ' . $unique, 'url' => 'https://example.test/search/' . $unique, 'source_type' => 'manual_search', 'priority' => 'B', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $manual = (int) $this->db->getInsertId();
        $this->db->query('INSERT INTO source_search_definitions', ['source_id' => $manual, 'name' => 'Synthetic query', 'version' => 1, 'query_text' => 'example maintenance', 'active' => true, 'created_at' => $now]);
    }

    private function withinTransaction(callable $test): void
    {
        try {
            $this->db->transaction(function () use ($test): void {
                $this->seed();
                $test();
                throw new \RuntimeException('rollback-preference-fixtures');
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'rollback-preference-fixtures') { throw $e; }
        }
    }

    private function rejects(callable $call): void
    {
        // Match top-level service rollback while keeping the outer fixture transaction.
        $this->db->query('SAVEPOINT expected_rejection');
        try { $call(); self::fail('Operace měla být odmítnuta.'); }
        catch (\InvalidArgumentException) { $this->db->query('ROLLBACK TO SAVEPOINT expected_rejection'); $this->addToAssertionCount(1); }
        finally { $this->db->query('RELEASE SAVEPOINT expected_rejection'); }
    }

    public function testPriorityConflictAuditAndManualSourceExclusion(): void
    {
        $this->withinTransaction(function (): void {
        $settings = $this->container->getByType(SourceSettingsService::class);
        $settings->save($this->user, $this->source, 1, 'B');
        self::assertSame('B', $settings->get($this->source)['priority']);
        $this->rejects(fn () => $settings->save($this->user, $this->source, 1, 'C'));
        $this->rejects(fn () => $settings->save($this->user, $this->source, 2, 'X'));
        $this->rejects(fn () => $settings->save($this->user + 1000000, $this->source, 2, 'A'));
        self::assertSame(1, (int) $this->db->fetchField("SELECT COUNT(*) FROM audit_log WHERE actor_user_id = ? AND event_type = 'source.priority_saved'", $this->user));
        $manual = (int) $settings->manualSources()[0]['id'];
        self::assertNotContains($manual, array_map(static fn ($s): int => $s->id, $this->container->getByType(SourceQueryService::class)->activeCheckableSources()));
        $this->rejects(fn () => $this->container->getByType(SearchRequestService::class)->request($this->user, [$manual], 'manual-rejected-request'));
        $s = $settings->get($manual);
        $settings->save($this->user, $manual, (int) $s['lock_version'], 'B', 'Test query', 'PHP maintenance');
        $settings->save($this->user, $manual, (int) $s['lock_version'] + 1, 'B', 'Test query', 'PHP maintenance updated', false);
        $latest = array_values(array_filter($settings->definitions($manual), static fn ($d): bool => $d['name'] === 'Test query'));
        self::assertCount(1, $latest);
        self::assertSame(2, (int) $latest[0]['version']);
        self::assertFalse((bool) $latest[0]['active']);
        });
    }

    public function testMultiStepExecutionKeepsPinsBudgetsAndResumeState(): void
    {
        $this->withinTransaction(function (): void {
            $unique = bin2hex(random_bytes(8));
            $now = new \DateTimeImmutable();
            $settings = $this->container->getByType(SourceSettingsService::class);
            $steps = [
                ['key' => 'original', 'name' => 'Original', 'query' => 'PHP', 'limit' => 2],
                ['key' => 'care', 'name' => 'Care', 'query' => 'maintenance', 'limit' => 2],
                ['key' => 'rescue', 'name' => 'Rescue', 'query' => 'takeover', 'limit' => 1],
            ];
            $settings->savePlan($this->user, $this->source, 1, json_encode($steps, JSON_THROW_ON_ERROR), 3, 'Review', 'Synthetic supported controls');
            $this->rejects(fn () => $settings->savePlan($this->user, $this->source, 1, json_encode($steps, JSON_THROW_ON_ERROR), 3, '', 'old version'));
            $requests = $this->container->getByType(SearchRequestService::class);
            $request = $requests->request($this->user, [$this->source], 'steps-' . $unique);
            $settings->savePlan($this->user, $this->source, 2, json_encode([$steps[0]], JSON_THROW_ON_ERROR), 2, 'New', 'Synthetic newer controls');
            $this->db->query('INSERT INTO api_clients', ['public_identifier' => $unique, 'name' => 'Step test', 'client_type' => 'runner', 'created_by_user_id' => $this->user, 'created_at' => $now]);
            $client = (int) $this->db->getInsertId();
            $this->db->query('INSERT INTO runner_devices', ['public_identifier' => $unique, 'api_client_id' => $client, 'name' => 'Step test', 'device_status' => 'offline', 'created_at' => $now]);
            $identity = new \App\Api\Auth\ApiIdentity(1, $client, $this->user, $unique, 'Step test', 'runner', ['search:execute']);
            $this->container->getByType(\App\Api\Auth\ApiRequestContext::class)->authenticate($identity);
            $execution = $this->container->getByType(\App\Search\ExecutionService::class);
            $lease = $execution->execute($identity, ['operation' => 'claim'])['lease'];
            $base = ['run_id' => $lease['run_id'], 'lease_token' => $lease['lease_token'], 'source_id' => $this->source];
            $call = fn (string $operation, string $key, array $payload): array => $execution->execute($identity, [...$base, 'operation' => $operation, 'idempotency_key' => 'step-test-' . $key, 'payload' => $payload]);
            $task = $execution->execute($identity, [...$base, 'operation' => 'task']);
            self::assertCount(3, $task['sources'][0]['search_plan']['steps']);
            $call('start', 'start-original', ['description' => 'Synthetic multi-step search']);
            $cp = ['completed_unit' => 'Start step', 'step_key' => 'original', 'step_status' => 'running', 'step_displayed_count' => 0];
            $this->rejects(fn () => $call('checkpoint', 'out-of-order', [...$cp, 'step_key' => 'care']));
            $call('checkpoint', 'original-start', $cp);
            $offer = ['url' => 'https://example.test/steps/' . $unique, 'originalTitle' => 'PHP', 'originalText' => 'Maintenance', 'searchStep' => 'original'];
            $import = $call('import', 'original-import', $offer);
            $this->rejects(fn () => $call('checkpoint', 'less-than-imports', [...$cp, 'step_status' => 'complete', 'step_completion_reason' => 'end_of_results']));
            $receipt = $call('checkpoint', 'original-end', [...$cp, 'step_status' => 'complete', 'step_displayed_count' => 1, 'step_completion_reason' => 'end_of_results']);
            self::assertSame($receipt, $call('checkpoint', 'original-end', [...$cp, 'step_status' => 'complete', 'step_displayed_count' => 1, 'step_completion_reason' => 'end_of_results']));
            $this->rejects(fn () => $call('import', 'after-step-complete', $offer));
            $call('finish', 'login-pause', ['status' => 'waiting_for_login', 'incomplete_reason' => 'Synthetic next-step login']);
            $this->container->getByType(\App\Search\SearchRequestControlService::class)->requestResume($this->user, $request->requestId);
            $next = $execution->execute($identity, ['operation' => 'claim'])['lease'];
            self::assertSame($lease['run_id'], $next['run_id']);
            $base['lease_token'] = $next['lease_token'];
            $call = fn (string $operation, string $key, array $payload): array => $execution->execute($identity, [...$base, 'operation' => $operation, 'idempotency_key' => 'step-test-' . $key, 'payload' => $payload]);
            $task = $execution->execute($identity, [...$base, 'operation' => 'task']);
            self::assertSame('complete', $task['sources'][0]['search_plan']['steps'][0]['status']);
            $call('start', 'resume-source', ['description' => 'Resume care step']);
            $cp['step_key'] = 'care';
            $call('checkpoint', 'care-start', $cp);
            $duplicate = $call('import', 'care-duplicate', [...$offer, 'searchStep' => 'care']);
            self::assertSame($import['opportunity_id'], $duplicate['opportunity_id']);
            $call('import', 'care-second', [...$offer, 'searchStep' => 'care', 'url' => $offer['url'] . '/second']);
            $this->rejects(fn () => $call('import', 'care-over-budget', [...$offer, 'searchStep' => 'care', 'url' => $offer['url'] . '/third']));
            $this->rejects(fn () => $call('checkpoint', 'invalid-count', [...$cp, 'step_displayed_count' => 3]));
            $call('checkpoint', 'care-end', [...$cp, 'step_status' => 'complete', 'step_displayed_count' => 2, 'step_completion_reason' => 'source_limit']);
            $task = $execution->execute($identity, [...$base, 'operation' => 'task']);
            self::assertSame('skipped_limit', $task['sources'][0]['search_plan']['steps'][2]['status']);
            $service = $this->container->getByType(\App\Search\SearchStepService::class);
            $service->assertFinished((int) $task['sources'][0]['run_source_id'], 3);
            $this->rejects(fn () => $service->assertFinished((int) $task['sources'][0]['run_source_id'], 2));
        });
    }

    public function testCounterpartyEvidenceHistoryAndOldImportCompatibility(): void
    {
        $this->withinTransaction(function (): void {
            $importer = $this->container->getByType(OpportunityImportService::class);
            $service = $this->container->getByType(\App\Opportunity\CounterpartyService::class);
            $url = 'https://example.test/party/' . bin2hex(random_bytes(8));
            $evidence = new \App\Opportunity\CounterpartyInput('owner', 'We own this application.', 0.9, new \DateTimeImmutable('2026-09-09T12:00:00Z'));
            $first = $importer->import(new OpportunityImport($url, 'PHP', 'Existing application', counterparty: $evidence), $this->user);
            $importer->import(new OpportunityImport($url, 'PHP', 'New text without classification'), $this->user);
            self::assertSame('owner', $service->history($first->opportunityId)[0]['value']);
            self::assertSame($first->sourceVersionId, (int) $service->history($first->opportunityId)[0]['source_version_id']);
            $version = (int) $this->db->fetchField('SELECT lock_version FROM opportunities WHERE id=?', $first->opportunityId);
            $service->save($first->opportunityId, $version, new \App\Opportunity\CounterpartyInput(null), $this->user);
            self::assertCount(2, $service->history($first->opportunityId));
            self::assertNull($service->history($first->opportunityId)[0]['value']);
            $detail = $this->container->getByType(\App\Opportunity\OpportunityQueryService::class)->getDetail($first->opportunityId, $this->user);
            self::assertNotNull($detail);
            self::assertCount(2, $detail->counterpartyHistory);
            self::assertSame(0, (int) $this->db->fetchField('SELECT COUNT(*) FROM user_opportunity_state WHERE opportunity_id=?', $first->opportunityId));
        });
    }

    public function testDiscoveryDeduplicationAndClassificationHistory(): void
    {
        $this->withinTransaction(function (): void {
        $importer = $this->container->getByType(OpportunityImportService::class);
        $care = $this->container->getByType(ProjectCareService::class);
        $settings = $this->container->getByType(SourceSettingsService::class);
        $definition = (int) $settings->manualSources()[0]['definitions'][0]['id'];
        $url = 'https://example.test/offer/' . bin2hex(random_bytes(8));
        $input = new ProjectCareInput(true, 'Poptávka výslovně žádá převzetí údržby.', 0.9, new \DateTimeImmutable('2026-09-09T09:00:00Z'));
        $first = $importer->import(new OpportunityImport($url, 'PHP', 'Údržba aplikace', projectCare: $input), $this->user, $this->source);
        $second = $importer->import(new OpportunityImport($url . '?utm_source=google', 'PHP', 'Údržba aplikace', discoveryDefinitionId: $definition), $this->user);
        self::assertSame($first->opportunityId, $second->opportunityId);
        self::assertCount(1, $care->history($first->opportunityId));
        self::assertTrue($care->history($first->opportunityId)[0]['value']);
        self::assertSame(2, (int) $this->db->fetchField('SELECT COUNT(*) FROM opportunity_sources WHERE opportunity_id = ?', $first->opportunityId));
        $this->rejects(fn () => $importer->import(new OpportunityImport($url, 'PHP', 'Údržba aplikace', discoveryDefinitionId: $definition), $this->user, $this->source));
        $version = (int) $this->db->fetchField('SELECT lock_version FROM opportunities WHERE id = ?', $first->opportunityId);
        $care->save($first->opportunityId, $version, new ProjectCareInput(null), $this->user);
        self::assertCount(2, $care->history($first->opportunityId));
        self::assertNull($care->history($first->opportunityId)[0]['value']);
        self::assertSame(0, (int) $this->db->fetchField('SELECT COUNT(*) FROM user_opportunity_state WHERE opportunity_id = ?', $first->opportunityId));
        });
    }

    public function testPinnedDefinitionSurvivesNewVersionAndDuplicateSubmission(): void
    {
        $this->withinTransaction(function (): void {
            $now = new \DateTimeImmutable();
            $this->db->query('INSERT INTO source_search_definitions', ['source_id' => $this->source, 'name' => 'PHP', 'version' => 1, 'query_text' => 'PHP', 'result_limit' => 20, 'active' => true, 'created_at' => $now]);
            $original = (int) $this->db->getInsertId();
            $requests = $this->container->getByType(SearchRequestService::class);
            $first = $requests->request($this->user, [$this->source], 'pin-original-request');
            $this->db->query('UPDATE source_search_definitions SET active=0 WHERE id=?', $original);
            $this->db->query('INSERT INTO source_search_definitions', ['source_id' => $this->source, 'name' => 'PHP', 'version' => 2, 'query_text' => 'PHP', 'review_guidance' => 'Include maintenance', 'result_limit' => 20, 'active' => true, 'created_at' => $now]);
            $next = (int) $this->db->getInsertId();
            $this->container->getByType(SourceSettingsService::class)->save($this->user, $this->source, 1, 'C');
            $duplicate = $requests->request($this->user, [$this->source], 'pin-original-request');
            self::assertFalse($duplicate->created);
            self::assertSame($first->requestId, $duplicate->requestId);
            self::assertSame($original, (int) $this->db->fetchField('SELECT search_definition_id FROM search_request_sources WHERE search_request_id=?', $first->requestId));
            $second = $requests->request($this->user, [$this->source], 'pin-next-request');
            self::assertSame($next, (int) $this->db->fetchField('SELECT search_definition_id FROM search_request_sources WHERE search_request_id=?', $second->requestId));
            $this->rejects(fn () => $requests->request($this->user, [], 'empty-request-key'));
        });
    }

    /** @return iterable<string, array{string, string}> */
    public static function accessResults(): iterable
    {
        foreach (['available', 'login_required', 'blocked', 'error'] as $access) {
            foreach (['partial', 'cancelled'] as $finish) {
                yield $access . '-' . $finish => [$access, $finish];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('accessResults')]
    public function testAccessPreparationRequiresOwnerConfirmationAndResumesSameRun(string $access, string $finish): void
    {
        $this->withinTransaction(function () use ($access, $finish): void {
        $unique = bin2hex(random_bytes(8));
        $now = new \DateTimeImmutable();
        $this->db->query('INSERT INTO api_clients', ['public_identifier' => $unique, 'name' => 'Preparation test', 'client_type' => 'runner', 'created_by_user_id' => $this->user, 'created_at' => $now]);
        $client = (int) $this->db->getInsertId();
        $this->db->query('INSERT INTO runner_devices', ['public_identifier' => $unique, 'api_client_id' => $client, 'name' => 'Preparation test', 'device_status' => 'offline', 'created_at' => $now]);
        $identity = new \App\Api\Auth\ApiIdentity(1, $client, $this->user, $unique, 'Preparation test', 'runner', ['search:execute']);
        $this->container->getByType(\App\Api\Auth\ApiRequestContext::class)->authenticate($identity);
        $requests = $this->container->getByType(SearchRequestService::class);
        $request = $requests->request($this->user, [$this->source], 'preparation-test-' . $unique, true);
        $this->rejects(fn () => $requests->request($this->user, [$this->source], 'preparation-test-' . $unique, false));
        $execution = $this->container->getByType(\App\Search\ExecutionService::class);
        $lease = $execution->execute($identity, ['operation' => 'claim'])['lease'];
        self::assertIsArray($lease);
        $base = ['run_id' => $lease['run_id'], 'lease_token' => $lease['lease_token'], 'source_id' => $this->source];
        self::assertTrue($execution->execute($identity, [...$base, 'operation' => 'task'])['prepare_access']);
        $this->rejects(fn () => $execution->execute($identity, [...$base, 'operation' => 'prepare_access', 'idempotency_key' => 'rejected-secret-key', 'payload' => ['status' => 'available', 'cookie' => 'not-a-real-secret']]));
        $this->rejects(fn () => $execution->execute($identity, [...$base, 'operation' => 'start', 'idempotency_key' => 'rejected-start-key', 'payload' => ['description' => 'Should not start']]));
        $prepare = [...$base, 'operation' => 'prepare_access', 'idempotency_key' => 'prepare-test-key-123', 'payload' => ['status' => $access]];
        $result = $execution->execute($identity, $prepare);
        self::assertSame('waiting_for_login', $result['request_status']);
        $state = (array) $this->db->fetch('SELECT * FROM source_access_states WHERE source_id = ?', $this->source);
        self::assertSame($access, $state['access_status']);
        self::assertSame($access === 'login_required', (bool) $state['intervention_required']);
        self::assertInstanceOf(\DateTimeInterface::class, $state['verified_at']);
        self::assertStringStartsWith('runner:', $state['verification_origin']);
        $views = array_values(array_filter($this->container->getByType(SourceQueryService::class)->activeCheckableSources(), fn ($s): bool => $s->id === $this->source));
        self::assertSame($access, $views[0]->accessStatus);
        self::assertNull($views[0]->lastSuccessAt);
        self::assertSame($result, $execution->execute($identity, $prepare));
        self::assertEquals($state, (array) $this->db->fetch('SELECT * FROM source_access_states WHERE source_id = ?', $this->source));
        self::assertNull($this->db->fetchField('SELECT pages_traversed FROM search_run_sources WHERE search_run_id = ?', $lease['run_id']));
        self::assertSame('planned', $this->db->fetchField('SELECT source_status FROM search_run_sources WHERE search_run_id = ?', $lease['run_id']));
        $preparation = $this->container->getByType(\App\Search\AccessPreparationService::class);
        $this->rejects(fn () => $preparation->confirm($this->user + 10000, $request->requestId));
        $preparation->confirm($this->user, $request->requestId);
        $preparation->confirm($this->user, $request->requestId);
        $next = $execution->execute($identity, ['operation' => 'claim'])['lease'];
        self::assertIsArray($next);
        self::assertSame($lease['run_id'], $next['run_id']);
        self::assertFalse($execution->execute($identity, ['operation' => 'task', 'run_id' => $next['run_id'], 'lease_token' => $next['lease_token']])['prepare_access']);
        $continued = [...$base, 'lease_token' => $next['lease_token']];
        $execution->execute($identity, [...$continued, 'operation' => 'start', 'idempotency_key' => 'access-start-test-123', 'payload' => ['description' => 'Synthetic access regression']]);
        $execution->execute($identity, [...$continued, 'operation' => 'finish', 'idempotency_key' => 'access-finish-test-123', 'payload' => ['status' => $finish, 'incomplete_reason' => 'Synthetic interruption']]);
        self::assertEquals($state, (array) $this->db->fetch('SELECT * FROM source_access_states WHERE source_id = ?', $this->source));
        });
    }
}
