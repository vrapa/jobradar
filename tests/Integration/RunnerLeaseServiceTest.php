<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Search\RunnerLeaseService;
use App\Search\SearchRequestService;
use App\Search\SearchRunService;
use App\Search\SourceRunResult;
use App\Search\SourceRunScope;
use App\Search\SourceQueryService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class RunnerLeaseServiceTest extends TestCase
{
    public function testExpiredLeaseResumesRemainingWorkAndPreservesCompletedResults(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $requests = $container->getByType(SearchRequestService::class);
        $leases = $container->getByType(RunnerLeaseService::class);
        $runs = $container->getByType(SearchRunService::class);
        $queries = $container->getByType(SourceQueryService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $sourceId = $secondSourceId = $deviceId = $requestId = $runId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test', 'display_name' => 'Synthetic runner user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $database->query('INSERT INTO sources', [
                'name' => 'Synthetic runner source ' . $unique, 'url' => 'https://runner-source.example.test',
                'market_code' => null, 'source_type' => 'public_api', 'priority' => 'A',
                'recommended_frequency_hours' => null, 'active' => true, 'access_requirement' => 'public',
                'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sourceId = (int) $database->getInsertId();
            $database->query('INSERT INTO sources', [
                'name' => 'Synthetic remaining runner source ' . $unique,
                'url' => 'https://runner-source.example.test/' . $unique . '/remaining',
                'market_code' => null, 'source_type' => 'public_api', 'priority' => 'A',
                'recommended_frequency_hours' => null, 'active' => true, 'access_requirement' => 'public',
                'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $secondSourceId = (int) $database->getInsertId();
            $database->query('INSERT INTO runner_devices', [
                'public_identifier' => '00000000-0000-4000-8000-' . substr($unique . str_repeat('0', 12), 0, 12),
                'name' => 'Synthetic runner', 'runner_version' => 'test', 'device_status' => 'offline',
                'created_at' => $now,
            ]);
            $deviceId = (int) $database->getInsertId();
            $requestId = $requests->request($userId, [$sourceId, $secondSourceId], 'runner-lease-test-' . $unique)->requestId;

            $lease = $leases->claimNext($deviceId, $userId);
            self::assertNotNull($lease);
            $runId = $lease->runId;
            self::assertSame($requestId, $lease->requestId);
            self::assertSame([$secondSourceId, $sourceId], $lease->sourceIds);
            self::assertSame(64, strlen($lease->token));
            self::assertSame(hash('sha256', $lease->token), $database->fetchField(
                'SELECT lease_token_hash FROM search_requests WHERE id = ?',
                $requestId,
            ));
            self::assertNotSame($lease->token, $database->fetchField(
                'SELECT lease_token_hash FROM search_requests WHERE id = ?',
                $requestId,
            ));
            self::assertSame('checking_access', $database->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $requestId));
            $planned = $database->fetch(
                'SELECT * FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            );
            self::assertNotNull($planned);
            self::assertSame('planned', $planned['source_status']);
            self::assertNull($planned['pages_traversed']);
            self::assertNull($planned['displayed_count']);
            self::assertNull($planned['finished_at']);
            $unavailableLease = $leases->claimNext($deviceId, $userId);
            self::assertNull($unavailableLease);
            $renewedUntil = $leases->renew($deviceId, $requestId, $lease->token);
            self::assertGreaterThanOrEqual($lease->expiresAt, $renewedUntil);
            self::assertSame($renewedUntil->format('Y-m-d H:i:s.u'), $database->fetchField(
                'SELECT DATE_FORMAT(lease_expires_at, ?) FROM search_requests WHERE id = ?',
                '%Y-%m-%d %H:%i:%s.%f',
                $requestId,
            ));
            try {
                $leases->renew($deviceId, $requestId, 'wrong-lease-token');
                self::fail('Cizí lease token nesmí obnovit běh.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Aktivní lease neexistuje, neodpovídá zařízení nebo vypršel.', $exception->getMessage());
            }

            $runs->startSource(
                $requestId,
                $lease->token,
                $sourceId,
                new SourceRunScope('První stránka veřejného API.', ['query' => 'php']),
            );
            self::assertSame('running', $database->fetchField(
                'SELECT source_status FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
            $runs->finishSource(
                $requestId,
                $lease->token,
                $sourceId,
                new SourceRunResult('complete', 1, 0, 0, 0, 0, 0, 0),
            );
            self::assertSame('running', $database->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $requestId));
            self::assertSame('running', $database->fetchField('SELECT run_status FROM search_runs WHERE id = ?', $runId));
            self::assertSame(0, (int) $database->fetchField(
                'SELECT displayed_count FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
            self::assertSame('První stránka veřejného API.', $database->fetchField(
                'SELECT query_text FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
            $runs->startSource(
                $requestId,
                $lease->token,
                $secondSourceId,
                new SourceRunScope('Rozpracovaný zdroj před přerušením.'),
            );
            $database->query(
                'UPDATE search_requests SET lease_expires_at = ? WHERE id = ?',
                $now->modify('-1 minute'),
                $requestId,
            );
            $resumedLease = $leases->claimNext($deviceId, $userId);
            self::assertNotNull($resumedLease);
            self::assertSame($runId, $resumedLease->runId);
            self::assertSame([$secondSourceId], $resumedLease->sourceIds);
            self::assertSame('planned', $database->fetchField(
                'SELECT source_status FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $secondSourceId,
            ));
            self::assertSame('complete', $database->fetchField(
                'SELECT source_status FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
            self::assertSame(0, (int) $database->fetchField(
                'SELECT displayed_count FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
            try {
                $runs->startSource(
                    $requestId,
                    $lease->token,
                    $secondSourceId,
                    new SourceRunScope('Starý lease nesmí pokračovat.'),
                );
                self::fail('Přerušený runner nesmí zapisovat starým lease tokenem.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Lease neexistuje, neodpovídá nebo vypršel.', $exception->getMessage());
            }
            $runs->startSource(
                $requestId,
                $resumedLease->token,
                $secondSourceId,
                new SourceRunScope('Druhá stránka veřejného API.'),
            );
            $runs->finishSource(
                $requestId,
                $resumedLease->token,
                $secondSourceId,
                new SourceRunResult('complete', 1, 1, 1, 1, 0, 0, 0),
            );
            self::assertSame('complete', $database->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $requestId));
            self::assertSame('complete', $database->fetchField('SELECT run_status FROM search_runs WHERE id = ?', $runId));
            self::assertNull($database->fetchField('SELECT lease_token_hash FROM search_requests WHERE id = ?', $requestId));
            $sourceViews = array_values(array_filter(
                $queries->activeCheckableSources(),
                static fn ($source): bool => $source->id === $sourceId,
            ));
            self::assertCount(1, $sourceViews);
            self::assertNotNull($sourceViews[0]->lastAttemptAt);
            self::assertNotNull($sourceViews[0]->lastSuccessAt);
            self::assertNull($sourceViews[0]->lastFoundAt);
            $coverage = $queries->latestCoverageSummary($userId);
            self::assertNotNull($coverage);
            self::assertSame(2, $coverage->plannedCount);
            self::assertSame(2, $coverage->completeCount);
            self::assertSame(0, $coverage->loginRequiredCount);
            self::assertSame(0, $coverage->errorCount);
            try {
                $leases->renew($deviceId, $requestId, $resumedLease->token);
                self::fail('Dokončený běh nesmí obnovit lease.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Aktivní lease neexistuje, neodpovídá zařízení nebo vypršel.', $exception->getMessage());
            }
        } finally {
            if ($runId !== null) {
                $database->query('DELETE FROM search_run_sources WHERE search_run_id = ?', $runId);
                $database->query('DELETE FROM search_runs WHERE id = ?', $runId);
            }
            if ($requestId !== null) {
                $database->query('DELETE FROM search_request_sources WHERE search_request_id = ?', $requestId);
                $database->query('DELETE FROM search_requests WHERE id = ?', $requestId);
                $database->query(
                    "DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.search_request_id')) = ?",
                    (string) $requestId,
                );
            }
            if ($deviceId !== null) {
                $database->query('DELETE FROM runner_devices WHERE id = ?', $deviceId);
            }
            if ($sourceId !== null) {
                $database->query('DELETE FROM source_access_states WHERE source_id = ?', $sourceId);
                $database->query('DELETE FROM sources WHERE id = ?', $sourceId);
            }
            if ($secondSourceId !== null) {
                $database->query('DELETE FROM source_access_states WHERE source_id = ?', $secondSourceId);
                $database->query('DELETE FROM sources WHERE id = ?', $secondSourceId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
