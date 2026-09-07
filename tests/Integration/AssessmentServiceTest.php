<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Assessment\AssessmentBreakdownInput;
use App\Assessment\AssessmentFindingInput;
use App\Assessment\AssessmentInput;
use App\Assessment\AssessmentRecommendation;
use App\Assessment\AssessmentQueryService;
use App\Assessment\AssessmentService;
use App\Bootstrap;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class AssessmentServiceTest extends TestCase
{
    public function testDraftRulesAllowExplanationButNotNumericScoreOrDecision(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $service = $container->getByType(AssessmentService::class);
        $queries = $container->getByType(AssessmentQueryService::class);
        $unique = bin2hex(random_bytes(8));
        $opportunityId = $profileId = $ruleSetId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO candidate_profiles', [
                'name' => 'Synthetic profile ' . $unique,
                'version' => 1,
                'description' => 'Synthetic integration profile.',
                'parameters_json' => '{}',
                'valid_from' => $now,
                'valid_until' => null,
                'created_by_user_id' => null,
                'created_at' => $now,
            ]);
            $profileId = (int) $database->getInsertId();
            $database->query('INSERT INTO scoring_rule_sets', [
                'name' => 'Synthetic draft rules ' . $unique,
                'version' => 1,
                'status' => 'draft',
                'rules_json' => '{}',
                'description' => 'No approved financial curve.',
                'created_by_user_id' => null,
                'created_at' => $now,
            ]);
            $ruleSetId = (int) $database->getInsertId();
            $opportunityId = $imports->import(new OpportunityImport(
                'https://jobs.example.test/assessment/' . $unique,
                'Synthetic assessment offer',
                'Rate is not stated. Remote conditions require verification.',
            ))->opportunityId;

            $result = $service->save(
                $opportunityId,
                1,
                new AssessmentInput(
                    $profileId,
                    $ruleSetId,
                    AssessmentRecommendation::Verify,
                    '0.500',
                    '0.600',
                    'Sazba a práce z ČR vyžadují ověření.',
                    authorType: 'system',
                ),
                [new AssessmentBreakdownInput('Podmínky', 'Klíčové údaje nejsou uvedené.')],
                [new AssessmentFindingInput('question', 'high', 'Je možná práce remote z ČR?', 'source_version')],
            );

            self::assertSame(2, $result->opportunityLockVersion);
            self::assertNull($database->fetchField('SELECT score_min FROM assessments WHERE id = ?', $result->assessmentId));
            self::assertSame('verify', $database->fetchField(
                'SELECT recommendation FROM opportunity_recommendations WHERE id = ?',
                $result->recommendationId,
            ));
            self::assertSame(1, (int) $database->fetchField(
                'SELECT COUNT(*) FROM assessment_findings WHERE assessment_id = ?',
                $result->assessmentId,
            ));
            self::assertSame(0, (int) $database->fetchField(
                'SELECT COUNT(*) FROM user_opportunity_state WHERE opportunity_id = ?',
                $opportunityId,
            ));
            $view = $queries->getCurrent($opportunityId);
            self::assertNotNull($view);
            self::assertSame(AssessmentRecommendation::Verify, $view->recommendation);
            self::assertSame('draft', $view->ruleSetStatus);
            self::assertNull($view->scoreMin);
            self::assertCount(1, $view->breakdowns);
            self::assertCount(1, $view->findings);

            try {
                $service->save(
                    $opportunityId,
                    2,
                    new AssessmentInput(
                        $profileId,
                        $ruleSetId,
                        AssessmentRecommendation::React,
                        '0.800',
                        '0.800',
                        'Numerické skóre nemá být přijato.',
                        scoreMin: '10',
                        scoreMax: '20',
                        authorType: 'system',
                    ),
                    [],
                    [],
                );
                self::fail('Návrhová pravidla nesmějí přijmout číselné skóre.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('aktivními schválenými pravidly', $exception->getMessage());
            }
            self::assertSame(2, (int) $database->fetchField('SELECT lock_version FROM opportunities WHERE id = ?', $opportunityId));
            self::assertSame(0, (int) $database->fetchField(
                'SELECT COUNT(*) FROM user_opportunity_state WHERE opportunity_id = ?',
                $opportunityId,
            ));
        } finally {
            if ($opportunityId !== null) {
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
        }
    }
}
