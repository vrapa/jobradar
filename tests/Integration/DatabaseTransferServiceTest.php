<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Infrastructure\DatabaseTransferService;
use PHPUnit\Framework\TestCase;

final class DatabaseTransferServiceTest extends TestCase
{
    public function testRestoreRefusesNonEmptyDatabaseBeforeStartingClient(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $transfer = $container->getByType(DatabaseTransferService::class);
        $path = tempnam(sys_get_temp_dir(), 'jobradar-restore-');
        self::assertIsString($path);
        $sqlPath = $path . '.sql';
        rename($path, $sqlPath);
        file_put_contents($sqlPath, "SELECT 1;\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Obnovu lze provést pouze do prázdné databáze.');
            $transfer->restore($sqlPath);
        } finally {
            @unlink($sqlPath);
        }
    }
}
