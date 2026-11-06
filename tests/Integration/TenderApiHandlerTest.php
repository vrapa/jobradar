<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Api\V1\ListOpportunitiesHandler;
use App\Api\V1\RecordTenderEventHandler;
use App\Bootstrap;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityType;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class TenderApiHandlerTest extends TestCase
{
    public function testApiClosesTenderAndListsItOnlyThroughClosedFilter(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $context = $container->getByType(ApiRequestContext::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $handler = $container->getByType(RecordTenderEventHandler::class);
        $list = $container->getByType(ListOpportunitiesHandler::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $opportunityId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => 'tender-api-' . $unique . '@example.test',
                'display_name' => 'Synthetic tender API owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $context->authenticate(new ApiIdentity(
                1,
                1,
                $userId,
                'synthetic-tender-api',
                'Synthetic tender API',
                'mcp',
                ['opportunities:read', 'tenders:write'],
            ));
            $opportunityId = $imports->import(new OpportunityImport(
                url: 'https://tender-api.example.test/' . $unique,
                originalTitle: 'Synthetic API tender',
                originalText: 'Synthetic tender content.',
                opportunityType: OpportunityType::TENDER,
            ), $userId)->opportunityId;

            $body = [
                'opportunity_id' => $opportunityId,
                'event' => 'closed',
                'expected_lock_version' => 0,
                'idempotency_key' => 'tender-api-close-' . $unique,
            ];
            $response = $handler->handle(['body' => $body]);
            self::assertInstanceOf(JsonApiResponse::class, $response);
            self::assertSame(200, $response->getCode());
            $payload = $response->getPayload();
            self::assertIsArray($payload);
            self::assertSame('closed', $payload['data']['workflow_status']);
            self::assertFalse($payload['data']['application_submitted']);
            self::assertFalse($payload['data']['decision_changed']);

            $active = self::payload($list->handle(['opportunity_type' => 'tender']));
            self::assertNotContains($opportunityId, array_column($active['data'], 'id'));
            $closed = self::payload($list->handle(['opportunity_type' => 'tender', 'tender_status' => 'closed']));
            self::assertContains($opportunityId, array_column($closed['data'], 'id'));
            self::assertSame('closed', array_values(array_filter($closed['data'], static fn (array $item): bool => $item['id'] === $opportunityId))[0]['tender_status']);

            $stale = $handler->handle(['body' => [
                'opportunity_id' => $opportunityId,
                'event' => 'reopened',
                'expected_lock_version' => 0,
                'idempotency_key' => 'tender-api-stale-' . $unique,
            ]]);
            self::assertInstanceOf(JsonApiResponse::class, $stale);
            self::assertSame(409, $stale->getCode());
        } finally {
            $context->clear();
            if (is_int($opportunityId)) {
                $database->query('DELETE FROM tender_events WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM tender_states WHERE opportunity_id = ?', $opportunityId);
                $database->query("DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?", (string) $opportunityId);
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
            }
            if (is_int($userId)) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function payload(object $response): array
    {
        self::assertInstanceOf(JsonApiResponse::class, $response);
        $payload = $response->getPayload();
        self::assertIsArray($payload);
        return $payload;
    }
}
