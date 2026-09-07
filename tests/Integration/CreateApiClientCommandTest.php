<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Console\CreateApiClientCommand;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateApiClientCommandTest extends TestCase
{
    public function testCommandShowsRawTokenOnceAndStoresOnlyHash(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $command = $container->getByType(CreateApiClientCommand::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $clientId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $email = $unique . '@example.test';
            $database->query('INSERT INTO users', [
                'email' => $email, 'display_name' => 'Synthetic CLI owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $tester = new CommandTester($command);
            $status = $tester->execute([
                'actor-email' => $email,
                'name' => 'Synthetic CLI MCP ' . $unique,
                'type' => 'mcp',
                '--scope' => ['sources:read', 'opportunities:read'],
                '--days' => '7',
            ]);

            self::assertSame(Command::SUCCESS, $status);
            self::assertSame(1, preg_match('/\bjr_[A-Za-z0-9_-]+\b/', $tester->getDisplay(), $matches));
            $rawToken = $matches[0] ?? null;
            self::assertIsString($rawToken);
            $clientId = (int) $database->fetchField('SELECT id FROM api_clients WHERE name = ?', 'Synthetic CLI MCP ' . $unique);
            self::assertGreaterThan(0, $clientId);
            self::assertSame(hash('sha256', $rawToken), $database->fetchField(
                'SELECT token_hash FROM api_access_tokens WHERE api_client_id = ?',
                $clientId,
            ));
            self::assertStringNotContainsString($rawToken, (string) $database->fetchField(
                'SELECT context_json FROM audit_log WHERE actor_user_id = ? AND event_type = ? ORDER BY id DESC LIMIT 1',
                $userId,
                'api.token_issued',
            ));
        } finally {
            if ($clientId !== null) {
                $database->query('DELETE FROM api_access_tokens WHERE api_client_id = ?', $clientId);
                $database->query('DELETE FROM api_clients WHERE id = ?', $clientId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
