<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Assessment\AssessmentConfigurationInput;
use App\Assessment\AssessmentConfigurationService;
use App\Bootstrap;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class AssessmentConfigurationServiceTest extends TestCase
{
    public function testCreatesImmutableProfileAndDraftRules(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $service = $container->getByType(AssessmentConfigurationService::class);
        $unique = bin2hex(random_bytes(8));
        $profileId = $ruleSetId = null;
        $input = new AssessmentConfigurationInput(
            'Synthetic profile ' . $unique,
            1,
            'Synthetic description.',
            ['remote' => true],
            'Synthetic rules ' . $unique,
            1,
            'Unapproved synthetic rules.',
            ['financialCurveApproved' => false],
        );

        try {
            $result = $service->createDraft($input);
            $profileId = $result->profileId;
            $ruleSetId = $result->ruleSetId;
            self::assertSame('draft', $database->fetchField('SELECT status FROM scoring_rule_sets WHERE id = ?', $ruleSetId));
            self::assertNull($database->fetchField('SELECT activated_at FROM scoring_rule_sets WHERE id = ?', $ruleSetId));
            self::assertSame(0, (int) $database->fetchField("SELECT COUNT(*) FROM scoring_rule_sets WHERE status = 'active' AND id = ?", $ruleSetId));

            $this->expectException(\InvalidArgumentException::class);
            $service->createDraft($input);
        } finally {
            if ($profileId !== null) {
                $database->query(
                    "DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.candidate_profile_id')) = ?",
                    (string) $profileId,
                );
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
