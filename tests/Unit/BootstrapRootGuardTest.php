<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapRootGuardTest extends TestCase
{
    public function testRootIsRejectedBeforeAccessingConfiguration(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires a root POSIX process; run separately from integration tests.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('docker compose exec --user www-data');
        (new Bootstrap('/nonexistent-jobradar-root-guard-test'))->bootConsole();
    }
}
