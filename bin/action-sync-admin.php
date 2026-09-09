<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$container = (new App\Bootstrap(dirname(__DIR__)))->bootConsole();
$database = $container->getByType(Nette\Database\Connection::class);
$credentials = $container->getByType(App\Api\Auth\ApiCredentialService::class);
$ownerId = filter_var(getenv('JOBRADAR_ACTION_SYNC_OWNER_ID'), FILTER_VALIDATE_INT);
if (!is_int($ownerId) || $ownerId < 1 || !$database->fetchField("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND deactivated_at IS NULL", $ownerId)) {
    fwrite(STDERR, "Action sync provisioning requires an explicit active owner ID.\n");
    exit(2);
}

$mode = $argv[1] ?? 'status';
$applications = getenv('JOBRADAR_APPLICATION_WORKFLOW') === '1';
$clientName = $applications ? 'Application workflow' : 'Todoist action sync';
$scopes = $applications ? ['applications:write', 'opportunities:read', 'action_items:read'] : ['action_items:read', 'action_items:write'];
if ($mode === 'status') {
    echo json_encode(array_map(static fn ($row): array => (array) $row, $database->fetchAll(
        "SELECT client.id AS client_id, client.public_identifier, token.id AS token_id, token.scopes_json, token.expires_at, token.revoked_at
         FROM api_clients client
         LEFT JOIN api_access_tokens token ON token.api_client_id = client.id
         WHERE client.name = ? AND client.client_type = ? AND client.created_by_user_id = ? AND client.revoked_at IS NULL",
        $clientName,
        'integration',
        $ownerId,
    )), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

if ($mode === 'revoke' && isset($argv[2])) {
    $tokenId = filter_var($argv[2], FILTER_VALIDATE_INT);
    if (!is_int($tokenId) || !$database->fetchField('SELECT id FROM api_access_tokens WHERE id = ? AND created_by_user_id = ?', $tokenId, $ownerId)) {
        exit(2);
    }
    $credentials->revokeToken($ownerId, $tokenId);
    echo "Todoist action sync token revoked; action items and external links preserved.\n";
    exit(0);
}

if ($mode !== 'create') {
    exit(2);
}

$result = $database->transaction(function () use ($database, $credentials, $ownerId, $clientName, $scopes): array {
    $name = $clientName;
    if ($database->fetchField('SELECT id FROM api_clients WHERE name = ? AND client_type = ? AND created_by_user_id = ? AND revoked_at IS NULL', $name, 'integration', $ownerId)) {
        throw new RuntimeException('Todoist action sync client already exists; use or explicitly revoke it instead of creating a duplicate.');
    }
    $client = $credentials->createClient($ownerId, $name, 'integration');
    $token = $credentials->issueToken($ownerId, $client->id, $scopes, new DateTimeImmutable('+90 days'));
    return [
        'token_id' => $token->id,
        'client_id' => $client->id,
        'token' => $token->token,
        'expires_at' => $token->expiresAt->format(DATE_ATOM),
    ];
});
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
