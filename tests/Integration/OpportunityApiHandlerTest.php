<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Api\V1\GetOpportunityHandler;
use App\Api\V1\ImportOpportunityHandler;
use App\Api\V1\ListOpportunitiesHandler;
use App\Api\V1\SetOpportunityDecisionHandler;
use App\Bootstrap;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class OpportunityApiHandlerTest extends TestCase
{
    public function testApiImportsAndReadsOpportunityForTokenOwner(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $context = $container->getByType(ApiRequestContext::class);
        $import = $container->getByType(ImportOpportunityHandler::class);
        $list = $container->getByType(ListOpportunitiesHandler::class);
        $get = $container->getByType(GetOpportunityHandler::class);
        $setDecision = $container->getByType(SetOpportunityDecisionHandler::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $opportunityId = null;
        $companyName = 'Synthetic API company ' . $unique;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test', 'display_name' => 'Synthetic opportunity API owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $context->authenticate(self::identity($userId));
            $body = [
                'url' => 'https://api-opportunity.example.test/' . $unique . '?utm_source=test',
                'originalTitle' => 'Untrusted API offer',
                'originalText' => '<script>neverExecute()</script>',
                'companyName' => $companyName,
                'incomplete' => true,
            ];

            $created = self::json($import->handle(['body' => $body]));
            self::assertSame(201, $created->getCode());
            $createdPayload = self::payload($created);
            $opportunityId = $createdPayload['data']['opportunity_id'];
            self::assertIsInt($opportunityId);
            self::assertTrue($createdPayload['data']['opportunity_created']);
            self::assertTrue($createdPayload['data']['version_created']);

            $repeated = self::json($import->handle(['body' => $body]));
            self::assertSame(200, $repeated->getCode());
            self::assertFalse(self::payload($repeated)['data']['version_created']);

            $listed = self::payload(self::json($list->handle([])));
            $listedIds = array_column($listed['data'], 'id');
            self::assertContains($opportunityId, $listedIds);

            $detail = self::payload(self::json($get->handle(['id' => $opportunityId])));
            self::assertSame('<script>neverExecute()</script>', $detail['data']['original_text']);
            self::assertSame('undecided', $detail['data']['decision']);
            self::assertSame('undecided', $detail['data']['decision_state']['decision']);
            self::assertTrue($detail['data']['incomplete']);
            self::assertSame(1, $detail['data']['version_count']);
            self::assertNull($detail['data']['current_assessment']);
            self::assertNull($detail['data']['rate']['min']);
            self::assertNull($detail['data']['assessment']['score_min']);
            self::assertSame(2, (int) $database->fetchField(
                "SELECT COUNT(*) FROM audit_log WHERE event_type = 'opportunity.manual_imported' AND actor_user_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?",
                $userId,
                (string) $opportunityId,
            ));

            $decisionBody = [
                'expected_lock_version' => 0,
                'decision' => 'react',
                'idempotency_key' => 'decision-' . $unique,
            ];
            $decision = self::json($setDecision->handle(['id' => $opportunityId, 'body' => $decisionBody]));
            self::assertSame(200, $decision->getCode());
            $decisionPayload = self::payload($decision);
            self::assertSame('react', $decisionPayload['data']['decision']);
            self::assertSame(1, $decisionPayload['data']['lock_version']);
            self::assertFalse($decisionPayload['data']['application_submitted']);
            self::assertSame('none', $database->fetchField(
                'SELECT workflow_status FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));
            self::assertSame('assistant', $database->fetchField(
                'SELECT actor_type FROM opportunity_decision_history WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));

            $replayed = self::json($setDecision->handle(['id' => $opportunityId, 'body' => $decisionBody]));
            self::assertEquals($decisionPayload, self::payload($replayed));
            self::assertSame(1, (int) $database->fetchField(
                'SELECT COUNT(*) FROM opportunity_decision_history WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));

            $reusedKey = $decisionBody;
            $reusedKey['decision'] = 'undecided';
            self::assertSame(422, self::json($setDecision->handle([
                'id' => $opportunityId,
                'body' => $reusedKey,
            ]))->getCode());

            $stale = $decisionBody;
            $stale['idempotency_key'] = 'stale-decision-' . $unique;
            self::assertSame(409, self::json($setDecision->handle([
                'id' => $opportunityId,
                'body' => $stale,
            ]))->getCode());
            self::assertSame(2, (int) $database->fetchField(
                'SELECT COUNT(*) FROM assistant_actions WHERE client_identifier = ?',
                'synthetic-opportunity-client',
            ));

            $invalid = self::json($import->handle(['body' => $body + ['unexpected' => 'value']]));
            self::assertSame(422, $invalid->getCode());
        } finally {
            $context->clear();
            if (is_int($opportunityId)) {
                $database->query('DELETE FROM assistant_actions WHERE client_identifier = ?', 'synthetic-opportunity-client');
                $database->query('DELETE FROM opportunity_decision_history WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM user_opportunity_state WHERE opportunity_id = ?', $opportunityId);
                $database->query(
                    "DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?",
                    (string) $opportunityId,
                );
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM companies WHERE normalized_name = ?', mb_strtolower($companyName));
            }
            if (is_int($userId)) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }

    private static function identity(int $userId): ApiIdentity
    {
        return new ApiIdentity(
            1,
            1,
            $userId,
            'synthetic-opportunity-client',
            'Synthetic opportunity client',
            'mcp',
            ['opportunities:read', 'opportunities:import'],
        );
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
