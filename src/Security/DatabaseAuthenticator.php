<?php

declare(strict_types=1);

namespace App\Security;

use App\Infrastructure\AuditLogger;
use Nette\Database\Explorer;
use Nette\Http\Request;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;

final class DatabaseAuthenticator implements Authenticator
{
    public function __construct(
        private readonly Explorer $database,
        private readonly Passwords $passwords,
        private readonly Request $request,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function authenticate(string $username, string $password): SimpleIdentity
    {
        $identifier = mb_strtolower(trim($username));
        $ipAddress = $this->request->getRemoteAddress() ?? 'unknown';
        $this->rateLimiter->assertAllowed($identifier, $ipAddress);

        $user = $this->database->table('users')
            ->where('email', $identifier)
            ->where('deactivated_at', null)
            ->fetch();

        if ($user === null || !$this->passwords->verify($password, (string) $user['password_hash'])) {
            $this->rateLimiter->record($identifier, $ipAddress, false);
            $this->auditLogger->record('authentication.failed', null, ['identifier_hash' => hash('sha256', $identifier)]);
            throw new AuthenticationException('Neplatné přihlašovací údaje.', self::InvalidCredential);
        }

        $this->rateLimiter->record($identifier, $ipAddress, true);
        $user->update(['last_login_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))]);
        if ($this->passwords->needsRehash((string) $user['password_hash'])) {
            $user->update(['password_hash' => $this->passwords->hash($password)]);
        }
        $this->auditLogger->record('authentication.succeeded', (int) $user['id']);

        return new SimpleIdentity(
            (int) $user['id'],
            [(string) $user['role']],
            ['email' => (string) $user['email'], 'displayName' => (string) $user['display_name']],
        );
    }
}
