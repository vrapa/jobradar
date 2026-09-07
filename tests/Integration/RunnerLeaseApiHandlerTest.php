<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Api\V1\ClaimRunnerLeaseHandler;
use App\Api\V1\RenewRunnerLeaseHandler;
use App\Api\V1\RecordSourceProgressHandler;
use App\Bootstrap;
use App\Search\SearchRequestService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class RunnerLeaseApiHandlerTest extends TestCase
{
    public function testRegisteredRunnerClaimsAndRenewsLease(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $context = $container->getByType(ApiRequestContext::class);
        $requests = $container->getByType(SearchRequestService::class);
        $claim = $container->getByType(ClaimRunnerLeaseHandler::class);
        $renew = $container->getByType(RenewRunnerLeaseHandler::class);
        $progress = $container->getByType(RecordSourceProgressHandler::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $clientId = $deviceId = $sourceId = $requestId = $runId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test', 'display_name' => 'Synthetic lease API owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $database->query('INSERT INTO api_clients', [
                'public_identifier' => '20000000-0000-4000-8000-' . substr($unique . str_repeat('0', 12), 0, 12),
                'name' => 'Synthetic lease client', 'client_type' => 'runner',
                'created_by_user_id' => $userId, 'created_at' => $now,
            ]);
            $clientId = (int) $database->getInsertId();
            $database->query('INSERT INTO runner_devices', [
                'api_client_id' => $clientId,
                'public_identifier' => '30000000-0000-4000-8000-' . substr($unique . str_repeat('0', 12), 0, 12),
                'name' => 'Synthetic lease runner', 'runner_version' => 'test', 'device_status' => 'inactive',
                'created_at' => $now,
            ]);
            $deviceId = (int) $database->getInsertId();
            $database->query('INSERT INTO sources', [
                'name' => 'Synthetic lease API source ' . $unique, 'url' => 'https://lease-api.example.test/' . $unique,
                'market_code' => null, 'source_type' => 'public_api', 'priority' => 'A',
                'recommended_frequency_hours' => null, 'active' => true, 'access_requirement' => 'public',
                'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sourceId = (int) $database->getInsertId();
            $requestId = $requests->request($userId, [$sourceId], 'runner-api-lease-' . $unique)->requestId;
            $context->authenticate(new ApiIdentity(
                1, $clientId, $userId, 'synthetic-runner', 'Synthetic runner', 'runner', ['search:write'],
            ));

            $claimResponse = self::json($claim->handle([]));
            self::assertSame(200, $claimResponse->getCode());
            $claimPayload = self::payload($claimResponse);
            self::assertSame($requestId, $claimPayload['data']['request_id']);
            $runId = $claimPayload['data']['run_id'];
            $leaseToken = $claimPayload['data']['lease_token'];
            self::assertIsInt($runId);
            self::assertIsString($leaseToken);
            self::assertSame(hash('sha256', $leaseToken), $database->fetchField(
                'SELECT lease_token_hash FROM search_requests WHERE id = ?',
                $requestId,
            ));

            $startResponse = self::json($progress->handle([
                'id' => $runId,
                'sourceId' => $sourceId,
                'body' => [
                    'event' => 'start',
                    'lease_token' => $leaseToken,
                    'scope' => ['description' => 'První stránka syntetického veřejného API.', 'filters' => ['query' => 'php']],
                ],
            ]));
            self::assertSame(200, $startResponse->getCode());
            self::assertSame('running', $database->fetchField(
                'SELECT source_status FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));

            $renewResponse = self::json($renew->handle(['body' => [
                'request_id' => $requestId,
                'lease_token' => $leaseToken,
            ]]));
            self::assertSame(200, $renewResponse->getCode());
            self::assertSame($requestId, self::payload($renewResponse)['data']['request_id']);

            $finishResponse = self::json($progress->handle([
                'id' => $runId,
                'sourceId' => $sourceId,
                'body' => [
                    'event' => 'finish',
                    'lease_token' => $leaseToken,
                    'result' => [
                        'status' => 'complete', 'pages_traversed' => 1, 'displayed_count' => 0,
                        'detail_opened_count' => 0, 'stored_count' => 0, 'updated_count' => 0,
                        'duplicate_count' => 0, 'rejected_count' => 0,
                    ],
                ],
            ]));
            self::assertSame(200, $finishResponse->getCode());
            self::assertSame('complete', $database->fetchField('SELECT request_status FROM search_requests WHERE id = ?', $requestId));
            self::assertSame(0, (int) $database->fetchField(
                'SELECT displayed_count FROM search_run_sources WHERE search_run_id = ? AND source_id = ?',
                $runId,
                $sourceId,
            ));
        } finally {
            $context->clear();
            if (is_int($runId)) {
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
            if ($deviceId !== null) {
                $database->query('DELETE FROM runner_devices WHERE id = ?', $deviceId);
            }
            if ($clientId !== null) {
                $database->query('DELETE FROM api_access_tokens WHERE api_client_id = ?', $clientId);
                $database->query('DELETE FROM api_clients WHERE id = ?', $clientId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }

    private static function json(object $response): JsonApiResponse
    {
        self::assertInstanceOf(JsonApiResponse::class, $response);
        return $response;
    }

    /** @return array<string, mixed> */
    private static function payload(JsonApiResponse $response): array
    {
        $payload = $response->getPayload();
        self::assertIsArray($payload);
        return $payload;
    }
}
