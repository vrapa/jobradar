<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Assessment\AssessmentInput;
use App\Assessment\AssessmentRecommendation;
use App\Assessment\AssessmentService;
use App\Api\Auth\ApiIdentity;
use App\Api\Auth\ApiRequestContext;
use App\Api\V1\ApplyDecisionDelegationHandler;
use App\Api\V1\CreateDecisionDelegationHandler;
use App\Api\V1\SetOpportunityDecisionHandler;
use App\Bootstrap;
use App\Decision\DecisionBatchItem;
use App\Decision\DecisionDelegationService;
use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class DecisionDelegationServiceTest extends TestCase
{
    public function testBatchRequiresApprovedRulesAndRollsBackOnNewerManualDecision(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $assessments = $container->getByType(AssessmentService::class);
        $decisions = $container->getByType(OpportunityDecisionService::class);
        $delegations = $container->getByType(DecisionDelegationService::class);
        $context = $container->getByType(ApiRequestContext::class);
        $createDelegation = $container->getByType(CreateDecisionDelegationHandler::class);
        $applyDelegation = $container->getByType(ApplyDecisionDelegationHandler::class);
        $setDecision = $container->getByType(SetOpportunityDecisionHandler::class);
        $unique = bin2hex(random_bytes(8));
        $clientIdentifier = 'synthetic-delegation-client-' . $unique;
        $userId = $profileId = $ruleSetId = $delegationId = $apiDelegationId = $singleDelegationId = null;
        $opportunityIds = [];

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test',
                'display_name' => 'Synthetic delegation user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $database->query('INSERT INTO candidate_profiles', [
                'name' => 'Synthetic delegation profile ' . $unique,
                'version' => 1,
                'description' => 'Synthetic integration profile.',
                'parameters_json' => '{}',
                'valid_from' => $now,
                'valid_until' => null,
                'created_by_user_id' => $userId,
                'created_at' => $now,
            ]);
            $profileId = (int) $database->getInsertId();
            $database->query('INSERT INTO scoring_rule_sets', [
                'name' => 'Synthetic delegation rules ' . $unique,
                'version' => 1,
                'status' => 'draft',
                'rules_json' => json_encode(['financialCurveApproved' => false], JSON_THROW_ON_ERROR),
                'description' => 'Synthetic integration rules.',
                'created_by_user_id' => $userId,
                'created_at' => $now,
            ]);
            $ruleSetId = (int) $database->getInsertId();

            foreach ([1, 2] as $number) {
                $opportunityId = $imports->import(new OpportunityImport(
                    sprintf('https://jobs.example.test/delegation/%s/%d', $unique, $number),
                    'Synthetic delegated offer ' . $number,
                    'Synthetic text with explicit source provenance.',
                ))->opportunityId;
                $opportunityIds[] = $opportunityId;
                $assessments->save(
                    $opportunityId,
                    1,
                    new AssessmentInput(
                        $profileId,
                        $ruleSetId,
                        AssessmentRecommendation::Verify,
                        '0.800',
                        '0.700',
                        'Syntetické posouzení pro test delegace.',
                        authorType: 'system',
                    ),
                    [],
                    [],
                );
            }

            try {
                $delegations->create(
                    $userId,
                    $opportunityIds,
                    $profileId,
                    $ruleSetId,
                    $now->modify('+1 hour'),
                );
                self::fail('Neschválená finanční křivka nesmí umožnit delegaci.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('aktivní schválená pravidla', $exception->getMessage());
            }

            $database->query('UPDATE scoring_rule_sets SET', [
                'status' => 'active',
                'rules_json' => json_encode(['financialCurveApproved' => true], JSON_THROW_ON_ERROR),
                'activated_at' => $now,
            ], 'WHERE id = ?', $ruleSetId);
            $delegation = $delegations->create(
                $userId,
                array_reverse($opportunityIds),
                $profileId,
                $ruleSetId,
                $now->modify('+1 hour'),
            );
            $delegationId = $delegation->id;
            self::assertSame($opportunityIds, $delegation->opportunityIds);

            $decisions->setManualDecision(
                $userId,
                $opportunityIds[0],
                0,
                OpportunityDecision::React,
            );
            try {
                $delegations->applyBatch($userId, $delegationId, [
                    new DecisionBatchItem($opportunityIds[0], 0, OpportunityDecision::React),
                    new DecisionBatchItem($opportunityIds[1], 0, OpportunityDecision::Uninteresting, 'low_rate'),
                ]);
                self::fail('Dávka se zastaralou verzí musí skončit konfliktem.');
            } catch (OpportunityConflictException) {
                self::assertSame(0, (int) $database->fetchField(
                    'SELECT COUNT(*) FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                    $userId,
                    $opportunityIds[1],
                ));
                self::assertSame('active', $database->fetchField(
                    'SELECT status FROM decision_delegations WHERE id = ?',
                    $delegationId,
                ));
            }

            $result = $delegations->applyBatch($userId, $delegationId, [
                new DecisionBatchItem($opportunityIds[0], 1, OpportunityDecision::React),
                new DecisionBatchItem($opportunityIds[1], 0, OpportunityDecision::Uninteresting, 'low_rate'),
            ]);
            self::assertSame(2, $result->processed);
            self::assertSame(1, $result->changed);
            self::assertSame('completed', $database->fetchField(
                'SELECT status FROM decision_delegations WHERE id = ?',
                $delegationId,
            ));
            self::assertSame('uninteresting', $database->fetchField(
                'SELECT decision FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityIds[1],
            ));
            self::assertSame('none', $database->fetchField(
                'SELECT workflow_status FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityIds[1],
            ));
            self::assertSame('assistant', $database->fetchField(
                'SELECT actor_type FROM opportunity_decision_history WHERE delegation_id = ?',
                $delegationId,
            ));

            $context->authenticate(new ApiIdentity(
                1,
                1,
                $userId,
                $clientIdentifier,
                'Synthetic delegation client',
                'mcp',
                ['decisions:write'],
            ));
            $createBody = [
                'opportunity_ids' => $opportunityIds,
                'candidate_profile_id' => $profileId,
                'scoring_rule_set_id' => $ruleSetId,
                'expires_at' => $now->modify('+2 hours')->format(DATE_ATOM),
                'idempotency_key' => 'create-delegation-' . $unique,
            ];
            $created = self::json($createDelegation->handle(['body' => $createBody]));
            self::assertSame(201, $created->getCode());
            $createdPayload = self::payload($created);
            $apiDelegationId = $createdPayload['data']['id'];
            self::assertIsInt($apiDelegationId);
            self::assertFalse($createdPayload['data']['application_submitted']);
            self::assertEquals(
                $createdPayload,
                self::payload(self::json($createDelegation->handle(['body' => $createBody]))),
            );

            $batchBody = [
                'decisions' => [
                    [
                        'opportunity_id' => $opportunityIds[0],
                        'expected_lock_version' => 1,
                        'decision' => 'uninteresting',
                        'reason' => 'workload',
                    ],
                    [
                        'opportunity_id' => $opportunityIds[1],
                        'expected_lock_version' => 1,
                        'decision' => 'uninteresting',
                        'reason' => 'low_rate',
                    ],
                ],
                'idempotency_key' => 'apply-delegation-' . $unique,
            ];
            $applied = self::json($applyDelegation->handle(['id' => $apiDelegationId, 'body' => $batchBody]));
            self::assertSame(200, $applied->getCode());
            $appliedPayload = self::payload($applied);
            self::assertSame(2, $appliedPayload['data']['processed']);
            self::assertSame(1, $appliedPayload['data']['changed']);
            self::assertFalse($appliedPayload['data']['application_submitted']);
            self::assertEquals(
                $appliedPayload,
                self::payload(self::json($applyDelegation->handle(['id' => $apiDelegationId, 'body' => $batchBody]))),
            );

            $singleCreateBody = $createBody;
            $singleCreateBody['opportunity_ids'] = [$opportunityIds[0]];
            $singleCreateBody['idempotency_key'] = 'create-single-delegation-' . $unique;
            $singleCreated = self::payload(self::json($createDelegation->handle(['body' => $singleCreateBody])));
            $singleDelegationId = $singleCreated['data']['id'];
            self::assertIsInt($singleDelegationId);
            $singleBody = [
                'delegation_id' => $singleDelegationId,
                'expected_lock_version' => 2,
                'decision' => 'react',
                'idempotency_key' => 'single-decision-' . $unique,
            ];
            $single = self::json($setDecision->handle([
                'id' => $opportunityIds[0],
                'body' => $singleBody,
            ]));
            self::assertSame(200, $single->getCode());
            $singlePayload = self::payload($single);
            self::assertSame($singleDelegationId, $singlePayload['data']['delegation_id']);
            self::assertSame(3, $singlePayload['data']['lock_version']);
            self::assertFalse($singlePayload['data']['application_submitted']);
        } finally {
            $context->clear();
            $database->query('DELETE FROM assistant_actions WHERE client_identifier = ?', $clientIdentifier);
            if ($opportunityIds !== []) {
                $database->query('DELETE FROM opportunity_decision_history WHERE opportunity_id IN (?)', $opportunityIds);
                $database->query('DELETE FROM user_opportunity_state WHERE opportunity_id IN (?)', $opportunityIds);
            }
            $delegationIds = array_values(array_filter(
                [$delegationId, $apiDelegationId, $singleDelegationId],
                static fn (?int $id): bool => $id !== null,
            ));
            if ($delegationIds !== []) {
                $database->query('DELETE FROM decision_delegations WHERE id IN (?)', $delegationIds);
            }
            foreach ($opportunityIds as $opportunityId) {
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
            if ($ruleSetId !== null) {
                $database->query('DELETE FROM scoring_rule_sets WHERE id = ?', $ruleSetId);
            }
            if ($profileId !== null) {
                $database->query('DELETE FROM candidate_profiles WHERE id = ?', $profileId);
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
