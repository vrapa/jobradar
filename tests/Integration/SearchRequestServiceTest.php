<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Search\SearchRequestService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SearchRequestServiceTest extends TestCase
{
    public function testExplicitRequestIsIdempotentAndDoesNotPretendSourcesWereChecked(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $service = $container->getByType(SearchRequestService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = null;
        $sourceIds = [];
        $requestId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test',
                'display_name' => 'Synthetic search user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            foreach (['one', 'two'] as $suffix) {
                $database->query('INSERT INTO sources', [
                    'name' => 'Synthetic source ' . $suffix . ' ' . $unique,
                    'url' => 'https://source-' . $suffix . '.example.test',
                    'market_code' => null,
                    'source_type' => 'public_api',
                    'priority' => 'A',
                    'recommended_frequency_hours' => null,
                    'active' => true,
                    'access_requirement' => 'public',
                    'adapter_capabilities' => '{}',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $sourceIds[] = (int) $database->getInsertId();
            }

            $key = 'synthetic-request-' . $unique;
            $first = $service->request($userId, [$sourceIds[1], $sourceIds[0]], $key);
            $requestId = $first->requestId;
            self::assertTrue($first->created);
            self::assertSame('waiting_for_runner', $first->status);
            $repeated = $service->request($userId, $sourceIds, $key);
            self::assertFalse($repeated->created);
            self::assertSame($first->requestId, $repeated->requestId);
            self::assertSame(1, (int) $database->fetchField(
                'SELECT COUNT(*) FROM search_requests WHERE requested_by_user_id = ?',
                $userId,
            ));
            self::assertSame(2, (int) $database->fetchField(
                'SELECT COUNT(*) FROM search_request_sources WHERE search_request_id = ?',
                $requestId,
            ));
            self::assertSame(0, (int) $database->fetchField(
                'SELECT COUNT(*) FROM search_runs WHERE search_request_id = ?',
                $requestId,
            ));

            try {
                $service->request($userId, [$sourceIds[0]], $key);
                self::fail('Stejný klíč s jiným výběrem musí být odmítnut.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('jiný výběr', $exception->getMessage());
            }
        } finally {
            if ($requestId !== null) {
                $database->query('DELETE FROM search_request_sources WHERE search_request_id = ?', $requestId);
                $database->query('DELETE FROM search_requests WHERE id = ?', $requestId);
                $database->query(
                    "DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.search_request_id')) = ?",
                    (string) $requestId,
                );
            }
            foreach ($sourceIds as $sourceId) {
                $database->query('DELETE FROM sources WHERE id = ?', $sourceId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
