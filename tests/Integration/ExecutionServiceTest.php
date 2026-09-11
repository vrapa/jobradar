<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Bootstrap;
use App\Search\ExecutionService;
use App\Search\SearchRequestService;
use App\Search\SourceQueryService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ExecutionServiceTest extends TestCase
{
    public function testOwnedExecutionReplayRecoveryAndCrossRequestCoverage(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $db = $container->getByType(Connection::class);
        $execution = $container->getByType(ExecutionService::class);
        $requests = $container->getByType(SearchRequestService::class);
        $queries = $container->getByType(SourceQueryService::class);
        $context = $container->getByType(ApiRequestContext::class);
        try {
            $db->transaction(function () use ($db, $execution, $requests, $queries, $context): void {
                $unique = bin2hex(random_bytes(8));
                $now = new \DateTimeImmutable();
                $db->query('INSERT INTO users', ['email' => $unique . '@example.test', 'display_name' => 'Execution test', 'password_hash' => 'unusable', 'role' => 'admin', 'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now]);
                $user = (int) $db->getInsertId();
                $db->query('INSERT INTO api_clients', ['public_identifier' => $unique, 'name' => 'Execution test', 'client_type' => 'runner', 'created_by_user_id' => $user, 'created_at' => $now]);
                $client = (int) $db->getInsertId();
                $db->query('INSERT INTO runner_devices', ['public_identifier' => $unique, 'api_client_id' => $client, 'name' => 'Execution test', 'device_status' => 'offline', 'created_at' => $now]);
                $device = (int) $db->getInsertId();
                $identity = new ApiIdentity(1, $client, $user, $unique, 'Execution test', 'runner', ['search:execute']);
                $context->authenticate($identity);
                $ids = [];
                for ($i = 0; $i < 2; $i++) {
                    $db->query('INSERT INTO sources', ['name' => 'Execution test ' . $unique . $i, 'url' => 'https://example.test/' . $unique . '/' . $i, 'source_type' => 'public_api', 'priority' => 'A', 'active' => true, 'access_requirement' => 'public', 'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                    $ids[] = (int) $db->getInsertId();
                }
                $first = $requests->request($user, $ids, 'execution-' . $unique);
                self::assertSame($first->requestId, $requests->request($user, $ids, 'execution-' . $unique)->requestId);
                $claim = $execution->execute($identity, ['operation' => 'claim']);
                self::assertIsArray($claim['lease']);
                $lease = $claim['lease'];
                self::assertSame($first->requestId, $lease['request_id']);
                self::assertNull($execution->execute($identity, ['operation' => 'claim'])['lease']);
                $base = ['run_id' => $lease['run_id'], 'lease_token' => $lease['lease_token'], 'source_id' => $ids[0]];
                $task = $execution->execute($identity, ['operation' => 'task', ...$base]);
                self::assertNull($task['profile']);
                self::assertArrayHasKey('schemas', $task);
                $start = ['operation' => 'start', ...$base, 'idempotency_key' => 'start-' . $unique, 'payload' => ['description' => 'Synthetic page one']];
                self::assertTrue($execution->execute($identity, $start)['recorded']);
                self::assertSame($execution->execute($identity, $start), $execution->execute($identity, $start));
                $checkpoint = ['operation' => 'checkpoint', ...$base, 'idempotency_key' => 'checkpoint-' . $unique, 'payload' => ['url' => 'https://example.test/page1', 'completed_unit' => 'page 1', 'pages_traversed' => 1]];
                self::assertTrue($execution->execute($identity, $checkpoint)['checkpoint_saved']);
                self::rejects(fn () => $execution->execute($identity, [...$checkpoint, 'source_id' => $ids[1] + 1000000]));
                self::rejects(fn () => $execution->execute($identity, [...$checkpoint, 'payload' => ['completed_unit' => 'changed data']]));
                self::rejects(fn () => $execution->execute(new ApiIdentity(1, $client, $user + 1000000, $unique, 'Other', 'runner', ['search:execute']), $checkpoint));
                self::rejects(fn () => $execution->execute(new ApiIdentity(1, $client, $user, $unique, 'Read', 'runner', ['sources:read']), $checkpoint));
                $finish = ['operation' => 'finish', ...$base, 'idempotency_key' => 'finish-' . $unique, 'payload' => ['status' => 'waiting_for_login', 'incomplete_reason' => 'Synthetic login gate']];
                $execution->execute($identity, $finish);
                self::assertSame('running', $db->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $first->requestId));
                $other = [...$base, 'source_id' => $ids[1]];
                $execution->execute($identity, [...$start, ...$other, 'idempotency_key' => 'start-other-' . $unique]);
                $db->query('UPDATE search_requests SET lease_expires_at = ? WHERE id = ?', $now->modify('-1 minute'), $first->requestId);
                self::rejects(fn () => $execution->execute($identity, ['operation' => 'import', ...$other, 'idempotency_key' => 'stale-import-' . $unique, 'payload' => ['url' => 'https://example.test/no', 'originalTitle' => 'Not imported', 'originalText' => 'No']]));
                $recovery = $execution->execute($identity, ['operation' => 'claim'])['lease'];
                self::assertIsArray($recovery);
                self::assertSame($lease['run_id'], $recovery['run_id']);
                self::assertNotSame($lease['lease_token'], $recovery['lease_token']);
                $other['lease_token'] = $recovery['lease_token'];
                $execution->execute($identity, [...$start, ...$other, 'idempotency_key' => 'recovered-start-' . $unique]);
                $end = ['operation' => 'finish', ...$other, 'idempotency_key' => 'final-' . $unique, 'payload' => ['status' => 'partial', 'incomplete_reason' => 'Synthetic time limit', 'pages_traversed' => 1]];
                $result = $execution->execute($identity, $end);
                self::assertSame($result, $execution->execute($identity, $end));
                self::assertNull($db->fetchField('SELECT lease_token_hash FROM search_requests WHERE id = ?', $first->requestId));
                self::assertSame('waiting_for_login', $db->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $first->requestId));
                self::assertCount(2, $queries->unresolvedSources($user));
                $db->query('INSERT INTO candidate_profiles', ['name' => 'Execution profile ' . $unique, 'version' => 1, 'description' => 'Synthetic only', 'parameters_json' => '{}', 'valid_from' => $now->modify('-1 minute'), 'created_by_user_id' => $user, 'created_at' => $now]);
                $profile = (int) $db->getInsertId();
                $db->query('INSERT INTO scoring_rule_sets', ['name' => 'Execution rules ' . $unique, 'version' => 1, 'status' => 'active', 'rules_json' => '{}', 'description' => 'Synthetic only', 'created_by_user_id' => $user, 'created_at' => $now]);
                $rules = (int) $db->getInsertId();
                $db->query('INSERT INTO source_search_definitions', ['source_id' => $ids[1], 'name' => 'Synthetic one page', 'version' => 1, 'query_text' => 'Synthetic PHP', 'filters_json' => '{}', 'pagination_strategy' => 'pages', 'result_limit' => 1, 'active' => true, 'created_at' => $now]);
                $requests->request($user, [$ids[1]], 'new-request-' . $unique);
                self::assertCount(2, $queries->unresolvedSources($user));
                $newLease = $execution->execute($identity, ['operation' => 'claim'])['lease'];
                self::assertIsArray($newLease);
                $newBase = ['run_id' => $newLease['run_id'], 'lease_token' => $newLease['lease_token'], 'source_id' => $ids[1]];
                $execution->execute($identity, [...$start, ...$newBase, 'idempotency_key' => 'pilot-start-' . $unique]);
                $import = ['operation' => 'import', ...$newBase, 'idempotency_key' => 'pilot-import-' . $unique, 'payload' => ['url' => 'https://example.test/' . $unique . '/offer', 'originalTitle' => 'Synthetic PHP', 'originalText' => 'Synthetic content; no real job', 'translatedText' => 'Syntetický obsah, nikoli pracovní nabídka', 'attachmentReviews' => [['key' => 'brief.pdf', 'name' => 'Brief', 'status' => 'pending', 'reason' => 'Download failed', 'questions' => 'Scope?', 'observedAt' => $now->format(DATE_ATOM)]]]];
                $imported = $execution->execute($identity, $import);
                self::assertTrue($imported['opportunity_created']);
                self::assertEquals($imported, $execution->execute($identity, $import));
                $assessment = ['operation' => 'assessment', ...$newBase, 'idempotency_key' => 'pilot-assessment-' . $unique, 'payload' => ['opportunityId' => $imported['opportunity_id'], 'expectedLockVersion' => $imported['lock_version'], 'candidateProfileId' => $profile, 'scoringRuleSetId' => $rules, 'recommendation' => 'verify', 'coverage' => '0', 'confidence' => '0.5', 'summary' => 'Synthetic unknown terms', 'modelIdentifier' => 'synthetic-test']];
                $assessed = $execution->execute($identity, $assessment);
                self::assertFalse($assessed['decision_changed']);
                self::assertEquals($assessed, $execution->execute($identity, $assessment));
                self::assertSame(0, (int) $db->fetchField('SELECT COUNT(*) FROM user_opportunity_state WHERE opportunity_id = ?', $imported['opportunity_id']));
                self::assertSame('codex', $db->fetchField('SELECT translation_method FROM source_versions WHERE id = ?', $imported['source_version_id']));
                $completed = ['operation' => 'finish', ...$newBase, 'idempotency_key' => 'pilot-finish-' . $unique, 'payload' => ['status' => 'complete', 'pages_traversed' => 1, 'displayed_count' => 1, 'detail_opened_count' => 1, 'stored_count' => 1, 'updated_count' => 0, 'duplicate_count' => 0, 'rejected_count' => 0]];
                self::rejects(fn () => $execution->execute($identity, $completed));
                $execution->execute($identity, [...$checkpoint, ...$newBase, 'idempotency_key' => 'pilot-checkpoint-' . $unique]);
                self::assertSame('complete', $execution->execute($identity, $completed)['request_status']);
                self::assertSame(1, (int) $db->fetchField("SELECT COUNT(*) FROM action_items WHERE user_id = ? AND action_type = 'review_opportunities' AND status = 'open'", $user));
                self::assertSame('complete', $execution->execute($identity, $completed)['request_status']);
                self::assertSame(1, (int) $db->fetchField("SELECT COUNT(*) FROM action_items WHERE user_id = ? AND action_type = 'review_opportunities'", $user));
                $detail = $queries->getRequestDetail($user, (int) $newLease['request_id']);
                self::assertNotNull($detail);
                self::assertSame(1, array_sum(array_map(static fn ($source): int => $source->pendingAttachmentOpportunityCount, $detail->sources)));
                self::assertCount(1, $queries->unresolvedSources($user));
                self::assertSame($ids[0], (int) $queries->unresolvedSources($user)[0]['id']);
                self::assertCount(0, $queries->unresolvedSources($user + 1000000));
                self::assertSame($device, $execution->execute($identity, ['operation' => 'status'])['device_id']);
                // One category entry can link multiple details. Pause must preserve both
                // counters and resume the same run without emitting a review summary.
                $db->query('UPDATE source_search_definitions SET active = 0 WHERE source_id = ?', $ids[1]);
                $db->query('INSERT INTO source_search_definitions', ['source_id' => $ids[1], 'name' => 'Synthetic category', 'version' => 2, 'query_text' => 'Synthetic alerts', 'filters_json' => '{}', 'pagination_strategy' => 'pages', 'result_limit' => 3, 'steps_json' => json_encode([['key' => 'alerts', 'name' => 'Alerts', 'mode' => 'category', 'query' => 'Synthetic alerts', 'filters' => [], 'limit' => 3]], JSON_THROW_ON_ERROR), 'active' => true, 'created_at' => $now]);
                $categoryRequest = $requests->request($user, [$ids[1]], 'category-request-' . $unique);
                $categoryLease = $execution->execute($identity, ['operation' => 'claim'])['lease'];
                $categoryBase = ['run_id' => $categoryLease['run_id'], 'lease_token' => $categoryLease['lease_token'], 'source_id' => $ids[1]];
                $execution->execute($identity, [...$start, ...$categoryBase, 'idempotency_key' => 'category-start-' . $unique]);
                $categoryCheckpoint = ['completed_unit' => 'Synthetic alert one', 'step_key' => 'alerts', 'step_status' => 'running', 'step_displayed_count' => 1, 'step_related_count' => 0, 'detail_opened_count' => 0];
                $execution->execute($identity, ['operation' => 'checkpoint', ...$categoryBase, 'idempotency_key' => 'category-init-' . $unique, 'payload' => $categoryCheckpoint]);
                for ($i = 0; $i < 2; $i++) {
                    $execution->execute($identity, ['operation' => 'import', ...$categoryBase, 'idempotency_key' => 'category-import-' . $i . $unique, 'payload' => ['url' => 'https://example.test/' . $unique . '/category/' . $i, 'originalTitle' => 'Synthetic linked detail', 'originalText' => 'Synthetic content.', 'searchStep' => 'alerts']]);
                }
                $categoryCheckpoint['step_related_count'] = 2;
                $categoryCheckpoint['detail_opened_count'] = 2;
                $pause = ['operation' => 'pause', ...$categoryBase, 'idempotency_key' => 'category-pause-' . $unique, 'payload' => $categoryCheckpoint];
                self::rejects(fn () => $execution->execute($identity, [...$pause, 'payload' => [...$categoryCheckpoint, 'step_related_count' => 1]]));
                self::assertTrue($execution->execute($identity, $pause)['paused']);
                self::assertTrue($execution->execute($identity, $pause)['paused']);
                self::assertSame('running', $db->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $categoryRequest->requestId));
                self::assertNull($db->fetchField('SELECT review_action_processed_at FROM search_runs WHERE id = ?', $categoryBase['run_id']));
                self::rejects(fn () => $execution->execute($identity, ['operation' => 'verify', ...$categoryBase]));
                $resumed = $execution->execute($identity, ['operation' => 'claim'])['lease'];
                self::assertSame($categoryBase['run_id'], $resumed['run_id']);
                self::assertNotSame($categoryBase['lease_token'], $resumed['lease_token']);
                $categoryBase['lease_token'] = $resumed['lease_token'];
                self::assertTrue($execution->execute($identity, ['operation' => 'verify', ...$categoryBase])['lease_valid']);
                $resumedTask = $execution->execute($identity, ['operation' => 'task', ...$categoryBase]);
                self::assertCount(2, $resumedTask['imported_opportunities']);
                self::assertSame(2, $resumedTask['sources'][0]['search_plan']['steps'][0]['checkpoint']['step_related_count']);
                $execution->execute($identity, [...$start, ...$categoryBase, 'idempotency_key' => 'category-resume-' . $unique]);
                $duplicate = $execution->execute($identity, ['operation' => 'import', ...$categoryBase, 'idempotency_key' => 'category-dedup-' . $unique, 'payload' => ['url' => 'https://example.test/' . $unique . '/category/0', 'originalTitle' => 'Synthetic linked detail', 'originalText' => 'Synthetic content.', 'searchStep' => 'alerts']]);
                self::assertFalse($duplicate['opportunity_created']);
                self::assertFalse($duplicate['version_created']);
                self::assertCount(2, $execution->execute($identity, ['operation' => 'task', ...$categoryBase])['imported_opportunities']);
                $execution->execute($identity, ['operation' => 'checkpoint', ...$categoryBase, 'idempotency_key' => 'category-end-' . $unique, 'payload' => [...$categoryCheckpoint, 'step_status' => 'complete', 'step_completion_reason' => 'end_of_results']]);
                self::assertSame('complete', $execution->execute($identity, ['operation' => 'finish', ...$categoryBase, 'idempotency_key' => 'category-finish-' . $unique, 'payload' => ['status' => 'complete', 'pages_traversed' => 1, 'displayed_count' => 1, 'detail_opened_count' => 2, 'stored_count' => 2, 'updated_count' => 0, 'duplicate_count' => 0, 'rejected_count' => 0]])['request_status']);
                self::assertNotNull($db->fetchField('SELECT review_action_processed_at FROM search_runs WHERE id = ?', $categoryBase['run_id']));
                throw new \DomainException('rollback-synthetic-execution');
            });
        } catch (\DomainException $exception) {
            self::assertSame('rollback-synthetic-execution', $exception->getMessage());
        } finally {
            $context->clear();
        }
    }

    private static function rejects(callable $action): void
    {
        try {
            $action();
            self::fail('Unsafe execution was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
