<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CompanyLeadMigrationTest extends TestCase
{
    public function testLegacyOpportunityTypesAreNormalizedBeforeConstraintIsAdded(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/021_company_leads.sql');
        self::assertIsString($migration);

        $normalization = "UPDATE opportunities\nSET opportunity_type = 'offer'\nWHERE opportunity_type NOT IN ('offer', 'company_lead', 'tender')";
        $constraint = 'ADD CONSTRAINT opportunities_type_valid CHECK';

        $normalizationPosition = strpos($migration, $normalization);
        $constraintPosition = strpos($migration, $constraint);

        self::assertNotFalse($normalizationPosition);
        self::assertNotFalse($constraintPosition);
        self::assertLessThan($constraintPosition, $normalizationPosition);
    }
}
