<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityQueryService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class OpportunityImportServiceTest extends TestCase
{
    public function testImportIsIdempotentAndCreatesVersionForChangedContent(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }

        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $service = $container->getByType(OpportunityImportService::class);
        $queries = $container->getByType(OpportunityQueryService::class);
        $database = $container->getByType(Connection::class);
        $unique = bin2hex(random_bytes(8));
        $baseUrl = sprintf('https://jobs.example.test/positions/%s', $unique);
        $companyName = 'Synthetic Company ' . $unique;
        $opportunityId = null;

        try {
            $first = $service->import(new OpportunityImport(
                url: $baseUrl . '?utm_source=test',
                originalTitle: 'Senior PHP Developer',
                originalText: 'Synthetic offer version one.',
                companyName: $companyName,
            ));
            $opportunityId = $first->opportunityId;
            self::assertTrue($first->opportunityCreated);
            self::assertTrue($first->versionCreated);

            $repeated = $service->import(new OpportunityImport(
                url: $baseUrl,
                originalTitle: 'Senior PHP Developer',
                originalText: 'Synthetic offer version one.',
                companyName: $companyName,
            ));
            self::assertSame($first->opportunityId, $repeated->opportunityId);
            self::assertSame($first->sourceVersionId, $repeated->sourceVersionId);
            self::assertFalse($repeated->opportunityCreated);
            self::assertFalse($repeated->versionCreated);

            $translated = $service->import(new OpportunityImport(
                url: $baseUrl,
                originalTitle: 'Senior PHP Developer',
                originalText: 'Synthetic offer version one.',
                companyName: $companyName,
                translatedTitle: 'Senior PHP vývojář',
                translatedText: 'Syntetický překlad nabídky.',
            ));
            self::assertFalse($translated->versionCreated);
            self::assertSame('completed', $database->fetchField(
                'SELECT translation_status FROM source_versions WHERE id = ?',
                $translated->sourceVersionId,
            ));

            $changed = $service->import(new OpportunityImport(
                url: $baseUrl,
                originalTitle: 'Senior PHP Developer',
                originalText: 'Synthetic offer version two with changed terms.',
                companyName: $companyName,
            ));
            self::assertSame($first->opportunityId, $changed->opportunityId);
            self::assertNotSame($first->sourceVersionId, $changed->sourceVersionId);
            self::assertTrue($changed->versionCreated);
            self::assertSame(2, (int) $database->fetchField(
                'SELECT COUNT(*) FROM source_versions WHERE opportunity_id = ?',
                $opportunityId,
            ));
            self::assertSame(2, (int) $database->fetchField(
                'SELECT lock_version FROM opportunities WHERE id = ?',
                $opportunityId,
            ));

            $detail = $queries->getDetail($opportunityId);
            self::assertNotNull($detail);
            self::assertSame('Synthetic offer version two with changed terms.', $detail->originalText);
            self::assertSame(2, $detail->versionCount);
            self::assertTrue(array_any(
                $queries->listCurrent(),
                static fn ($item): bool => $item->id === $opportunityId,
            ));
            self::assertSame(4, (int) $database->fetchField(
                "SELECT COUNT(*) FROM audit_log WHERE event_type = 'opportunity.manual_imported' AND JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?",
                (string) $opportunityId,
            ));
        } finally {
            if ($opportunityId !== null) {
                $database->query(
                    "DELETE FROM audit_log WHERE event_type = 'opportunity.manual_imported' AND JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?",
                    (string) $opportunityId,
                );
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM companies WHERE normalized_name = ?', mb_strtolower($companyName));
            }
        }
    }
}
