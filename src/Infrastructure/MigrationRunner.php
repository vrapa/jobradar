<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Nette\Database\Connection;

final class MigrationRunner
{
    public function __construct(
        private readonly Connection $database,
        private readonly string $migrationDirectory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->database->query(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $applied = [];
        foreach ($this->database->fetchAll('SELECT version FROM schema_migrations') as $row) {
            $applied[(string) $row['version']] = true;
        }
        $files = glob($this->migrationDirectory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $executed = [];

        foreach ($files as $file) {
            $version = basename($file);
            if (isset($applied[$version])) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException(sprintf('Nelze načíst migraci %s.', $version));
            }
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($statements as $statement) {
                $this->database->getPdo()->exec($statement);
            }
            $this->database->query('INSERT INTO schema_migrations', [
                'version' => $version,
                'applied_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ]);
            $executed[] = $version;
        }

        return $executed;
    }
}
