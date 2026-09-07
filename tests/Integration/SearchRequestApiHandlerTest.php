<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Api\V1\CancelSearchRequestHandler;
use App\Api\V1\CreateSearchRequestHandler;
use App\Api\V1\GetSearchRequestHandler;
use App\Api\V1\ResumeSearchRequestHandler;
use App\Bootstrap;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class SearchRequestApiHandlerTest extends TestCase
{
    public function testApiCreatesReadsAndCancelsOnlyOwnersRequest(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $context = $container->getByType(ApiRequestContext::class);
        $create = $container->getByType(CreateSearchRequestHandler::class);
        $get = $container->getByType(GetSearchRequestHandler::class);
        $cancel = $container->getByType(CancelSearchRequestHandler::class);
        $resume = $container->getByType(ResumeSearchRequestHandler::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $sourceId = $requestId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test', 'display_name' => 'Synthetic API search owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $database->query('INSERT INTO sources', [
                'name' => 'Synthetic API source ' . $unique, 'url' => 'https://api-source.example.test/' . $unique,
                'market_code' => null, 'source_type' => 'api', 'priority' => 'A',
                'recommended_frequency_hours' => null, 'active' => true, 'access_requirement' => 'public',
                'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sourceId = (int) $database->getInsertId();
            $context->authenticate(self::identity($userId));
            $params = ['body' => [
                'source_ids' => [$sourceId],
                'idempotency_key' => 'api-search-' . $unique,
            ]];

            $created = self::json($create->handle($params));
            self::assertSame(201, $created->getCode());
            $createdPayload = self::payload($created);
            self::assertTrue($createdPayload['data']['created']);
            $requestId = $createdPayload['data']['id'];
            self::assertIsInt($requestId);

            $repeated = self::json($create->handle($params));
            self::assertSame(200, $repeated->getCode());
            self::assertFalse(self::payload($repeated)['data']['created']);

            $detail = self::json($get->handle(['id' => $requestId]));
            self::assertSame(200, $detail->getCode());
            $detailPayload = self::payload($detail);
            self::assertSame(1, $detailPayload['data']['coverage']['planned_sources']);
            self::assertSame(0, $detailPayload['data']['coverage']['completely_checked_sources']);
            self::assertNull($detailPayload['data']['sources'][0]['counts']['pages_traversed']);

            $context->authenticate(self::identity($userId + 1));
            self::assertSame(404, self::json($get->handle(['id' => $requestId]))->getCode());

            $context->authenticate(self::identity($userId));
            self::assertSame(200, self::json($cancel->handle(['id' => $requestId]))->getCode());
            self::assertSame(409, self::json($resume->handle(['id' => $requestId]))->getCode());
            $cancelled = self::payload(self::json($get->handle(['id' => $requestId])));
            self::assertSame('cancelled', $cancelled['data']['status']);
        } finally {
            $context->clear();
            if (is_int($requestId)) {
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
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }

    private static function identity(int $userId): ApiIdentity
    {
        return new ApiIdentity(1, 1, $userId, 'synthetic-client', 'Synthetic client', 'mcp', ['search:control']);
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
