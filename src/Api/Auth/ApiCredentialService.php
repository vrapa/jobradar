<?php

declare(strict_types=1);

namespace App\Api\Auth;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class ApiCredentialService
{
    private const CLIENT_TYPES = ['runner', 'mcp', 'integration'];

    private const SCOPES = [
        'sources:read',
        'search:control',
        'search:write',
        'opportunities:read',
        'opportunities:import',
        'assessments:write',
        'decisions:recommend',
        'decisions:write',
        'applications:write',
        'audit:read',
    ];

    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @return list<string> */
    public function supportedClientTypes(): array
    {
        return self::CLIENT_TYPES;
    }

    /** @return list<string> */
    public function supportedScopes(): array
    {
        return self::SCOPES;
    }

    public function createClient(int $actorUserId, string $name, string $type): ApiClientRegistration
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Název API klienta musí mít 1 až 120 znaků.');
        }
        if (!in_array($type, self::CLIENT_TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ API klienta.');
        }
        $identifier = self::uuidV4();
        $now = self::now();
        $this->database->query('INSERT INTO api_clients', [
            'public_identifier' => $identifier,
            'name' => $name,
            'client_type' => $type,
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
        ]);
        $clientId = (int) $this->database->getInsertId();
        $this->auditLogger->record('api.client_created', $actorUserId, [
            'api_client_id' => $clientId,
            'client_type' => $type,
        ]);
        return new ApiClientRegistration($clientId, $identifier);
    }

    /** @param list<string> $scopes */
    public function issueToken(
        int $actorUserId,
        int $clientId,
        array $scopes,
        \DateTimeImmutable $expiresAt,
    ): ApiTokenIssue {
        $client = $this->database->fetch('SELECT id, revoked_at FROM api_clients WHERE id = ?', $clientId);
        if (!$client instanceof Row || $client['revoked_at'] !== null) {
            throw new \InvalidArgumentException('Aktivní API klient nebyl nalezen.');
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);
        if ($scopes === [] || array_any($scopes, static fn (string $scope): bool => !in_array($scope, self::SCOPES, true))) {
            throw new \InvalidArgumentException('Token musí obsahovat pouze podporovaná oprávnění.');
        }
        $now = self::now();
        $expiresAt = $expiresAt->setTimezone(new \DateTimeZone('UTC'));
        if ($expiresAt <= $now) {
            throw new \InvalidArgumentException('Expirace API tokenu musí být v budoucnosti.');
        }
        $token = 'jr_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefix = substr($token, 0, 12);
        $this->database->query('INSERT INTO api_access_tokens', [
            'api_client_id' => $clientId,
            'token_prefix' => $prefix,
            'token_hash' => hash('sha256', $token),
            'scopes_json' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'created_by_user_id' => $actorUserId,
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ]);
        $tokenId = (int) $this->database->getInsertId();
        $this->auditLogger->record('api.token_issued', $actorUserId, [
            'api_client_id' => $clientId,
            'api_token_id' => $tokenId,
            'token_prefix' => $prefix,
        ]);
        return new ApiTokenIssue($tokenId, $token, $prefix, $scopes, $expiresAt);
    }

    public function authenticate(string $token, string $requiredScope): ApiIdentity
    {
        if (!in_array($requiredScope, self::SCOPES, true)) {
            throw new \LogicException('API požaduje neznámé oprávnění.');
        }
        $row = $this->database->fetch(
            'SELECT token.id AS token_id, token.scopes_json, token.expires_at, token.revoked_at AS token_revoked_at,
                    client.id AS client_id, client.created_by_user_id, client.public_identifier, client.name, client.client_type,
                    client.revoked_at AS client_revoked_at
             FROM api_access_tokens token
             INNER JOIN api_clients client ON client.id = token.api_client_id
             WHERE token.token_hash = ?',
            hash('sha256', $token),
        );
        $now = self::now();
        if (!$row instanceof Row
            || $row['token_revoked_at'] !== null
            || $row['client_revoked_at'] !== null
            || !$row['expires_at'] instanceof \DateTimeInterface
            || $row['expires_at'] <= $now
        ) {
            throw new ApiAuthenticationException('API token není platný.');
        }
        $scopes = self::decodeScopes((string) $row['scopes_json']);
        if (!in_array($requiredScope, $scopes, true)) {
            throw new ApiAuthenticationException('API token nemá požadované oprávnění.');
        }
        $this->database->query('UPDATE api_access_tokens SET last_used_at = ? WHERE id = ?', $now, $row['token_id']);
        return new ApiIdentity(
            tokenId: (int) $row['token_id'],
            clientId: (int) $row['client_id'],
            ownerUserId: (int) $row['created_by_user_id'],
            clientIdentifier: (string) $row['public_identifier'],
            clientName: (string) $row['name'],
            clientType: (string) $row['client_type'],
            scopes: $scopes,
        );
    }

    public function revokeToken(int $actorUserId, int $tokenId): bool
    {
        $now = self::now();
        $result = $this->database->query(
            'UPDATE api_access_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            $now,
            $tokenId,
        );
        $changed = $result->getRowCount() === 1;
        if ($changed) {
            $this->auditLogger->record('api.token_revoked', $actorUserId, ['api_token_id' => $tokenId]);
        }
        return $changed;
    }

    /** @return list<string> */
    private static function decodeScopes(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_any($decoded, static fn (mixed $scope): bool => !is_string($scope))) {
            throw new \UnexpectedValueException('Uložená oprávnění API tokenu nejsou platná.');
        }
        /** @var list<string> $decoded */
        return $decoded;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
