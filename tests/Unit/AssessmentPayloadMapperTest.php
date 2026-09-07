<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Assessment\AssessmentPayloadMapper;
use App\Assessment\AssessmentRecommendation;
use PHPUnit\Framework\TestCase;

final class AssessmentPayloadMapperTest extends TestCase
{
    public function testMapsExplainableAssessment(): void
    {
        $payload = (new AssessmentPayloadMapper())->map(<<<'JSON'
            {
              "opportunityId":12,"expectedLockVersion":3,"candidateProfileId":2,"scoringRuleSetId":4,
              "recommendation":"verify","coverage":"0.500","confidence":"0.600",
              "summary":"Needs verification.","authorType":"assistant","modelIdentifier":"test-model",
              "breakdowns":[{"area":"Terms","explanation":"Unknown terms."}],
              "findings":[{"type":"question","severity":"high","text":"Is remote allowed?"}]
            }
            JSON);

        self::assertSame(12, $payload->opportunityId);
        self::assertSame(AssessmentRecommendation::Verify, $payload->assessment->recommendation);
        self::assertCount(1, $payload->breakdowns);
        self::assertCount(1, $payload->findings);
        self::assertNull($payload->assessment->scoreMin);
    }

    public function testRequiresDecimalsAsStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AssessmentPayloadMapper())->map(<<<'JSON'
            {
              "opportunityId":1,"expectedLockVersion":1,"candidateProfileId":1,"scoringRuleSetId":1,
              "recommendation":"verify","coverage":0.5,"confidence":"0.6","summary":"Summary",
              "authorType":"system"
            }
            JSON);
    }

    public function testCliPayloadCannotImpersonateUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AssessmentPayloadMapper())->map(<<<'JSON'
            {
              "opportunityId":1,"expectedLockVersion":1,"candidateProfileId":1,"scoringRuleSetId":1,
              "recommendation":"verify","coverage":"0.5","confidence":"0.6","summary":"Summary",
              "authorType":"user"
            }
            JSON);
    }
}
