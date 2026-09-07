<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Api\V1\SaveOpportunityAssessmentHandler;
use App\Api\V1\GetOpportunityHandler;
use App\Bootstrap;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class AssessmentApiHandlerTest extends TestCase
{
    public function testApiAssessmentIsIdempotentAndDoesNotChangeDecision(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $context = $container->getByType(ApiRequestContext::class);
        $handler = $container->getByType(SaveOpportunityAssessmentHandler::class);
        $getOpportunity = $container->getByType(GetOpportunityHandler::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $profileId = $ruleSetId = $opportunityId = null;
        $clientIdentifier = 'synthetic-assessment-client-' . $unique;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test', 'display_name' => 'Synthetic assessment API owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $database->query('INSERT INTO candidate_profiles', [
                'name' => 'Synthetic API profile ' . $unique, 'version' => 1,
                'description' => 'Synthetic profile.', 'parameters_json' => '{}',
                'valid_from' => $now, 'valid_until' => null, 'created_by_user_id' => $userId, 'created_at' => $now,
            ]);
            $profileId = (int) $database->getInsertId();
            $database->query('INSERT INTO scoring_rule_sets', [
                'name' => 'Synthetic API rules ' . $unique, 'version' => 1, 'status' => 'draft',
                'rules_json' => '{}', 'description' => 'Explanation only.',
                'created_by_user_id' => $userId, 'created_at' => $now,
            ]);
            $ruleSetId = (int) $database->getInsertId();
            $opportunityId = $imports->import(new OpportunityImport(
                'https://jobs.example.test/api-assessment/' . $unique,
                'Synthetic API assessment offer',
                'Remote terms need verification.',
            ))->opportunityId;
            $context->authenticate(new ApiIdentity(
                1, 1, $userId, $clientIdentifier, 'Synthetic assessment client', 'mcp', ['assessments:write'],
            ));
            $body = [
                'expectedLockVersion' => 1,
                'candidateProfileId' => $profileId,
                'scoringRuleSetId' => $ruleSetId,
                'recommendation' => 'verify',
                'coverage' => '0.500',
                'confidence' => '0.600',
                'summary' => 'Remote terms require verification.',
                'modelIdentifier' => 'synthetic-model',
                'breakdowns' => [['area' => 'Terms', 'explanation' => 'Unknown remote terms.']],
                'findings' => [['type' => 'question', 'severity' => 'high', 'text' => 'Is Czech remote work allowed?']],
                'idempotencyKey' => 'assessment-' . $unique,
            ];

            $created = self::json($handler->handle(['id' => $opportunityId, 'body' => $body]));
            self::assertSame(201, $created->getCode());
            $payload = self::payload($created);
            self::assertSame(2, $payload['data']['opportunity_lock_version']);
            self::assertFalse($payload['data']['decision_changed']);
            self::assertFalse($payload['data']['application_submitted']);
            self::assertSame(0, (int) $database->fetchField(
                'SELECT COUNT(*) FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));
            $detail = self::payload(self::json($getOpportunity->handle(['id' => $opportunityId])));
            self::assertSame('verify', $detail['data']['current_assessment']['recommendation']);
            self::assertSame('synthetic-model', $detail['data']['current_assessment']['model_identifier']);
            self::assertSame('high', $detail['data']['current_assessment']['findings'][0]['severity']);

            $replayed = self::json($handler->handle(['id' => $opportunityId, 'body' => $body]));
            self::assertSame(201, $replayed->getCode());
            self::assertEquals($payload, self::payload($replayed));
            self::assertSame(1, (int) $database->fetchField(
                'SELECT COUNT(*) FROM assessments WHERE opportunity_id = ?',
                $opportunityId,
            ));

            $stale = $body;
            $stale['idempotencyKey'] = 'stale-assessment-' . $unique;
            self::assertSame(409, self::json($handler->handle(['id' => $opportunityId, 'body' => $stale]))->getCode());
        } finally {
            $context->clear();
            $database->query('DELETE FROM assistant_actions WHERE client_identifier = ?', $clientIdentifier);
            if (is_int($opportunityId)) {
                $database->query('DELETE b FROM assessment_breakdowns b INNER JOIN assessments a ON a.id = b.assessment_id WHERE a.opportunity_id = ?', $opportunityId);
                $database->query('DELETE f FROM assessment_findings f INNER JOIN assessments a ON a.id = f.assessment_id WHERE a.opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_recommendations WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM assessments WHERE opportunity_id = ?', $opportunityId);
                $database->query(
                    "DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?",
                    (string) $opportunityId,
                );
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
            }
            if (is_int($ruleSetId)) {
                $database->query('DELETE FROM scoring_rule_sets WHERE id = ?', $ruleSetId);
            }
            if (is_int($profileId)) {
                $database->query('DELETE FROM candidate_profiles WHERE id = ?', $profileId);
            }
            if (is_int($userId)) {
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
