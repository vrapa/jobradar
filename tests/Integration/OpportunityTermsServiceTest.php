<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityTermsInput;
use App\Opportunity\OpportunityTermsService;
use App\Opportunity\TechnologyInput;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class OpportunityTermsServiceTest extends TestCase
{
    public function testSavesVersionBoundTermsAndRejectsStaleWrite(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }

        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $imports = $container->getByType(OpportunityImportService::class);
        $service = $container->getByType(OpportunityTermsService::class);
        $database = $container->getByType(Connection::class);
        $unique = bin2hex(random_bytes(8));
        $opportunityId = null;

        try {
            $import = $imports->import(new OpportunityImport(
                url: 'https://jobs.example.test/terms/' . $unique,
                originalTitle: 'Synthetic PHP role',
                originalText: 'Synthetic terms service fixture.',
            ));
            $opportunityId = $import->opportunityId;
            $newVersion = $service->save(
                opportunityId: $opportunityId,
                expectedLockVersion: 1,
                terms: new OpportunityTermsInput(
                    rateMin: null,
                    rateMax: '900.00',
                    currency: 'CZK',
                    rateUnit: 'hour',
                    rateSource: 'Text nabídky',
                    rateConfidence: '0.800',
                    workFromCzechia: null,
                    workingLanguage: 'čeština',
                ),
                technologies: [
                    new TechnologyInput('PHP', 'required', true, 'high', 'Uvedeno v požadavcích.'),
                    new TechnologyInput('Nette', 'advantage'),
                ],
            );

            self::assertSame(2, $newVersion);
            $terms = $database->fetch('SELECT * FROM opportunity_terms WHERE opportunity_id = ?', $opportunityId);
            self::assertNotNull($terms);
            self::assertNull($terms['rate_min']);
            self::assertSame(900.0, (float) $terms['rate_max']);
            self::assertSame('Text nabídky', $terms['rate_source']);
            self::assertNull($terms['work_from_czechia']);
            self::assertSame(2, (int) $database->fetchField(
                'SELECT COUNT(*) FROM technology_requirements WHERE opportunity_id = ?',
                $opportunityId,
            ));

            $this->expectException(OpportunityConflictException::class);
            $service->save($opportunityId, 1, new OpportunityTermsInput(), []);
        } finally {
            if ($opportunityId !== null) {
                $database->query(
                    "DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?",
                    (string) $opportunityId,
                );
                $database->query('DELETE FROM technology_requirements WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_terms WHERE opportunity_id = ?', $opportunityId);
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
            }
        }
    }
}
