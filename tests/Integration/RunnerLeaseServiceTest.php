<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Search\RunnerLeaseService;
use App\Search\SearchRequestService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class RunnerLeaseServiceTest extends TestCase
{
    public function testClaimStoresOnlyTokenHashAndKeepsMetricsUnknown(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $requests = $container->getByType(SearchRequestService::class);
        $leases = $container->getByType(RunnerLeaseService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $sourceId = $deviceId = $requestId = $runId = null;

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
            $database->query('INSERT INTO runner_devices', [
                'public_identifier' => '00000000-0000-4000-8000-' . substr($unique . str_repeat('0', 12), 0, 12),
                'name' => 'Synthetic runner', 'runner_version' => 'test', 'device_status' => 'offline',
                'created_at' => $now,
            ]);
            $deviceId = (int) $database->getInsertId();
            $requestId = $requests->request($userId, [$sourceId], 'runner-lease-test-' . $unique)->requestId;

            $lease = $leases->claimNext($deviceId);
            self::assertNotNull($lease);
            $runId = $lease->runId;
            self::assertSame($requestId, $lease->requestId);
            self::assertSame([$sourceId], $lease->sourceIds);
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
            $planned = $database->fetch('SELECT * FROM search_run_sources WHERE search_run_id = ?', $runId);
            self::assertNotNull($planned);
            self::assertSame('planned', $planned['source_status']);
            self::assertNull($planned['pages_traversed']);
            self::assertNull($planned['displayed_count']);
            self::assertNull($planned['finished_at']);
            self::assertNull($leases->claimNext($deviceId));
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
                $database->query('DELETE FROM sources WHERE id = ?', $sourceId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
