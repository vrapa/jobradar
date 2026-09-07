<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Assessment\AssessmentConfigurationMapper;
use PHPUnit\Framework\TestCase;

final class AssessmentConfigurationMapperTest extends TestCase
{
    public function testMapsVersionedProfileAndRules(): void
    {
        $input = (new AssessmentConfigurationMapper())->map(<<<'JSON'
            {
              "profile": {"name":"Profile","version":2,"description":"Description","parameters":{"remote":true}},
              "ruleSet": {"name":"Rules","version":3,"description":"Draft rules","rules":{"curveApproved":false}}
            }
            JSON);

        self::assertSame('Profile', $input->profileName);
        self::assertSame(2, $input->profileVersion);
        self::assertTrue($input->profileParameters['remote']);
        self::assertFalse($input->rules['curveApproved']);
    }

    public function testRejectsUnknownField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AssessmentConfigurationMapper())->map(<<<'JSON'
            {
              "profile": {"name":"Profile","version":1,"description":"Description","parameters":{},"secret":"no"},
              "ruleSet": {"name":"Rules","version":1,"description":"Draft","rules":{}}
            }
            JSON);
    }
}
