<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class OpportunitySchemaTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if ($host === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $this->database = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $host,
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'jobradar',
            ),
            getenv('DB_USER') ?: 'jobradar',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public function testCatalogTablesExist(): void
    {
        $expected = [
            'companies',
            'duplicate_candidates',
            'opportunities',
            'opportunity_questions',
            'opportunity_sources',
            'opportunity_terms',
            'source_versions',
            'sources',
            'technology_requirements',
        ];
        $statement = $this->database->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name",
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $actual = $statement->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expected as $table) {
            self::assertContains($table, $actual);
        }
    }

    public function testUnknownTermsRemainNullable(): void
    {
        $statement = $this->database->query(
            "SELECT column_name, is_nullable
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'opportunity_terms'
               AND column_name IN ('rate_min', 'rate_confidence', 'work_from_czechia', 'verified_at')",
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $columns = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        ksort($columns);

        self::assertSame([
            'rate_confidence' => 'YES',
            'rate_min' => 'YES',
            'verified_at' => 'YES',
            'work_from_czechia' => 'YES',
        ], $columns);
    }
}
