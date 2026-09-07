<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\Auth\ApiAuthenticationException;
use App\Api\Auth\ApiCredentialService;
use App\Api\Auth\ScopedBearerAuthorization;
use App\Bootstrap;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ApiCredentialServiceTest extends TestCase
{
    public function testTokenIsHashedScopedExpiringAndRevocable(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $credentials = $container->getByType(ApiCredentialService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = $clientId = $tokenId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test', 'display_name' => 'Synthetic API owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin',
                'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $client = $credentials->createClient($userId, 'Synthetic MCP ' . $unique, 'mcp');
            $clientId = $client->id;
            $issue = $credentials->issueToken(
                $userId,
                $clientId,
                ['opportunities:read', 'sources:read', 'sources:read'],
                $now->modify('+1 hour'),
            );
            $tokenId = $issue->id;

            self::assertStringStartsWith('jr_', $issue->token);
            self::assertSame(substr($issue->token, 0, 12), $issue->prefix);
            self::assertSame(['opportunities:read', 'sources:read'], $issue->scopes);
            self::assertSame(hash('sha256', $issue->token), $database->fetchField(
                'SELECT token_hash FROM api_access_tokens WHERE id = ?',
                $tokenId,
            ));
            self::assertNotSame($issue->token, $database->fetchField(
                'SELECT token_hash FROM api_access_tokens WHERE id = ?',
                $tokenId,
            ));

            $identity = $credentials->authenticate($issue->token, 'sources:read');
            self::assertSame($clientId, $identity->clientId);
            self::assertTrue($identity->hasScope('opportunities:read'));
            self::assertNotNull($database->fetchField('SELECT last_used_at FROM api_access_tokens WHERE id = ?', $tokenId));

            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issue->token;
            $authorization = new ScopedBearerAuthorization($credentials, 'sources:read');
            self::assertTrue($authorization->authorized());
            $wrongScopeAuthorization = new ScopedBearerAuthorization($credentials, 'search:control');
            self::assertFalse($wrongScopeAuthorization->authorized());
            self::assertSame(
                'API token není platný nebo nemá požadované oprávnění.',
                $wrongScopeAuthorization->getErrorMessage(),
            );

            try {
                $credentials->authenticate($issue->token, 'search:control');
                self::fail('Token bez požadovaného scope nesmí být přijat.');
            } catch (ApiAuthenticationException $exception) {
                self::assertSame('API token nemá požadované oprávnění.', $exception->getMessage());
            }

            self::assertTrue($credentials->revokeToken($userId, $tokenId));
            self::assertFalse($credentials->revokeToken($userId, $tokenId));
            $this->expectException(ApiAuthenticationException::class);
            $credentials->authenticate($issue->token, 'sources:read');
        } finally {
            unset($_SERVER['HTTP_AUTHORIZATION']);
            if ($tokenId !== null) {
                $database->query('DELETE FROM api_access_tokens WHERE id = ?', $tokenId);
            }
            if ($clientId !== null) {
                $database->query('DELETE FROM api_clients WHERE id = ?', $clientId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
