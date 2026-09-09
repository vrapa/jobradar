<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Search\RunnerLeaseService;
use App\Search\SearchRequestControlService;
use App\Search\SearchRequestService;
use App\Search\SearchRunService;
use App\Search\SourceQueryService;
use App\Search\SourceRunResult;
use App\Search\SourceRunScope;
use App\Api\V1\LoginRequiredSourcesHandler;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SearchRequestControlServiceTest extends TestCase
{
    public function testResumeRequiresUserRequestAndCancelPreservesRecordedResult(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $requests = $container->getByType(SearchRequestService::class);
        $leases = $container->getByType(RunnerLeaseService::class);
        $runs = $container->getByType(SearchRunService::class);
        $controls = $container->getByType(SearchRequestControlService::class);
        $queries = $container->getByType(SourceQueryService::class);
        $loginSourcesHandler = $container->getByType(LoginRequiredSourcesHandler::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $sourceId = $secondSourceId = $deviceId = $requestId = $runId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test', 'display_name' => 'Synthetic control user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $database->query('INSERT INTO sources', [
                'name' => 'Synthetic login source ' . $unique, 'url' => 'https://login-source.example.test',
                'market_code' => null, 'source_type' => 'browser', 'priority' => 'A',
                'recommended_frequency_hours' => null, 'active' => true, 'access_requirement' => 'login',
                'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sourceId = (int) $database->getInsertId();
            $database->query('INSERT INTO sources', [
                'name' => 'Synthetic public source ' . $unique, 'url' => 'https://public-source.example.test/' . $unique,
                'market_code' => null, 'source_type' => 'public_api', 'priority' => 'A',
                'recommended_frequency_hours' => null, 'active' => true, 'access_requirement' => 'public',
                'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $secondSourceId = (int) $database->getInsertId();
            $database->query('INSERT INTO runner_devices', [
                'public_identifier' => '10000000-0000-4000-8000-' . substr($unique . str_repeat('0', 12), 0, 12),
                'name' => 'Synthetic control runner', 'runner_version' => 'test', 'device_status' => 'offline',
                'created_at' => $now,
            ]);
            $deviceId = (int) $database->getInsertId();
            $requestId = $requests->request($userId, [$sourceId, $secondSourceId], 'runner-control-test-' . $unique)->requestId;
            $lease = $leases->claimNext($deviceId, $userId);
            self::assertNotNull($lease);
            $runId = $lease->runId;
            $runs->startSource($requestId, $lease->token, $sourceId, new SourceRunScope('Přihlášený seznam nabídek.'));
            $runs->finishSource(
                $requestId,
                $lease->token,
                $sourceId,
                new SourceRunResult('waiting_for_login', incompleteReason: 'Relace vyžaduje přihlášení.'),
            );
            self::assertSame('running', $database->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $requestId));
            $waitingDetail = $queries->getRequestDetail($userId, $requestId);
            self::assertNotNull($waitingDetail);
            self::assertFalse($waitingDetail->canResume());
            self::assertSame(0, $waitingDetail->checkedSourceCount());
            self::assertCount(2, $waitingDetail->sources);
            self::assertSame('Přihlášený seznam nabídek.', $waitingDetail->sources[0]->queryText);
            self::assertTrue($waitingDetail->sources[0]->loginRequired);
            self::assertNull($waitingDetail->sources[0]->pagesTraversed);
            self::assertNull($queries->getRequestDetail($userId + 1, $requestId));
            $loginResponse = $loginSourcesHandler->handle([]);
            self::assertInstanceOf(JsonApiResponse::class, $loginResponse);
            $loginPayload = $loginResponse->getPayload();
            self::assertIsArray($loginPayload);
            self::assertContains($sourceId, array_column($loginPayload['data'], 'id'));

            $runs->startSource($requestId, $lease->token, $secondSourceId, new SourceRunScope('Jedna stránka veřejného API.'));
            $runs->finishSource(
                $requestId,
                $lease->token,
                $secondSourceId,
                new SourceRunResult('complete', 1, 0, 0, 0, 0, 0, 0),
            );
            self::assertSame('waiting_for_login', $database->fetchField(
                'SELECT request_status FROM search_requests WHERE id = ?',
                $requestId,
            ));
            self::assertSame('resume_requested', $controls->requestResume($userId, $requestId));
            self::assertNull($database->fetchField('SELECT lease_token_hash FROM search_requests WHERE id = ?', $requestId));

            $resumedLease = $leases->claimNext($deviceId, $userId);
            self::assertNotNull($resumedLease);
            self::assertSame($runId, $resumedLease->runId);
            self::assertSame([$sourceId], $resumedLease->sourceIds);
            $runs->startSource($requestId, $resumedLease->token, $sourceId, new SourceRunScope('Přihlášený seznam po zásahu uživatele.'));
            self::assertSame('cancelled', $controls->cancel($userId, $requestId));
            self::assertSame('cancelled', $controls->cancel($userId, $requestId));
            self::assertSame('cancelled', $database->fetchField('SELECT run_status FROM search_runs WHERE id = ?', $runId));
            self::assertSame('cancelled', $database->fetchField(
                'SELECT source_status FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
            self::assertNotNull($database->fetchField(
                'SELECT started_at FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
            $cancelledDetail = $queries->getRequestDetail($userId, $requestId);
            self::assertNotNull($cancelledDetail);
            self::assertFalse($cancelledDetail->canCancel());
            self::assertSame('Přihlášený seznam po zásahu uživatele.', $cancelledDetail->sources[0]->queryText);
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
            if ($sourceId !== null) {
                $database->query('DELETE FROM source_access_states WHERE source_id = ?', $sourceId);
                $database->query('DELETE FROM sources WHERE id = ?', $sourceId);
            }
            if ($secondSourceId !== null) {
                $database->query('DELETE FROM source_access_states WHERE source_id = ?', $secondSourceId);
                $database->query('DELETE FROM sources WHERE id = ?', $secondSourceId);
            }
            if ($deviceId !== null) {
                $database->query('DELETE FROM runner_devices WHERE id = ?', $deviceId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
