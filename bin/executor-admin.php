<?php

declare(strict_types=1);

// Administrative provisioning only. The MCP process never loads this file or the database.
require dirname(__DIR__) . '/vendor/autoload.php';
$container = (new App\Bootstrap(dirname(__DIR__)))->bootConsole();
$db = $container->getByType(Nette\Database\Connection::class);
$credentials = $container->getByType(App\Api\Auth\ApiCredentialService::class);
$mode = $argv[1] ?? 'status';
if ($mode === 'status') {
    echo json_encode([
        'active_admin_count' => (int) $db->fetchField("SELECT COUNT(*) FROM users WHERE role = 'admin' AND deactivated_at IS NULL"),
        'active_admins' => array_map(static fn ($row): array => (array) $row, $db->fetchAll("SELECT id, display_name FROM users WHERE role = 'admin' AND deactivated_at IS NULL")),
        'active_source_count' => (int) $db->fetchField("SELECT COUNT(*) FROM sources WHERE active = 1 AND archived_at IS NULL AND source_type <> 'manual'"),
        'search_definition_count' => (int) $db->fetchField('SELECT COUNT(*) FROM source_search_definitions WHERE active = 1 AND archived_at IS NULL'),
        'profile_count' => (int) $db->fetchField('SELECT COUNT(*) FROM candidate_profiles'),
        'recent_request_owners' => array_map(static fn ($row): array => (array) $row, $db->fetchAll("SELECT id, requested_by_user_id AS owner_id, request_status, runner_device_id FROM search_requests ORDER BY id DESC LIMIT 5")),
        'executor_owners' => array_map(static fn ($row): array => (array) $row, $db->fetchAll("SELECT d.id AS device_id, c.created_by_user_id AS owner_id, d.last_seen_at FROM runner_devices d JOIN api_clients c ON c.id = d.api_client_id WHERE c.name = 'Codex Desktop executor' AND c.revoked_at IS NULL AND d.revoked_at IS NULL")),
        'pending_request_owners' => array_map(static fn ($row): array => (array) $row, $db->fetchAll("SELECT id, requested_by_user_id AS owner_id FROM search_requests WHERE executor_eligible = 1 AND request_status IN ('waiting_for_runner', 'resume_requested')")),
        'active_rules_count' => (int) $db->fetchField("SELECT COUNT(*) FROM scoring_rule_sets WHERE status = 'active'"),
        'legacy_waiting_requests' => array_map(static fn ($row): array => (array) $row, $db->fetchAll("SELECT id, request_status FROM search_requests WHERE executor_eligible = 0 AND request_status IN ('waiting_for_runner', 'resume_requested', 'running', 'checking_access')")),
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}
$owners = $db->fetchAll("SELECT id FROM users WHERE role = 'admin' AND deactivated_at IS NULL");
$explicitOwner = getenv('JOBRADAR_EXECUTOR_OWNER_ID');
$existingMcpToken = getenv('JOBRADAR_MCP_TOKEN');
if (is_string($explicitOwner) && $explicitOwner !== '') {
    $ownerId = filter_var($explicitOwner, FILTER_VALIDATE_INT);
    if (!is_int($ownerId) || $ownerId < 1) { exit(2); }
    $owners = $db->fetchAll("SELECT id FROM users WHERE id = ? AND role = 'admin' AND deactivated_at IS NULL", $ownerId);
} elseif (is_string($existingMcpToken) && $existingMcpToken !== '') {
    $mcpIdentity = $credentials->authenticate($existingMcpToken, 'sources:read');
    $owners = $db->fetchAll("SELECT id FROM users WHERE id = ? AND role = 'admin' AND deactivated_at IS NULL", $mcpIdentity->ownerUserId);
}
if (count($owners) !== 1) {
    fwrite(STDERR, 'Provisioning requires an unambiguous active administrator (or the existing MCP owner); choose explicitly using api:create-client.' . PHP_EOL);
    exit(2);
}
$owner = (int) $owners[0]['id'];
if ($mode === 'import-config' && isset($argv[2])) {
    $path = $argv[2];
    if (!is_file($path) || filesize($path) > 1048576) { exit(2); }
    $config = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $assessment = $container->getByType(App\Assessment\AssessmentConfigurationMapper::class)->map(json_encode($config['assessment'], JSON_THROW_ON_ERROR));
    $result = $db->transaction(function () use ($db, $container, $owner, $config, $assessment): array {
        $profile = $db->fetchField('SELECT id FROM candidate_profiles WHERE name = ? AND version = ? AND created_by_user_id = ?', $assessment->profileName, $assessment->profileVersion, $owner);
        if ($profile === null) {
            $created = $container->getByType(App\Assessment\AssessmentConfigurationService::class)->createDraft($assessment, $owner);
            $profile = $created->profileId;
        }
        $sourceIds = [];
        foreach ($config['sources'] as $source) {
            $url = parse_url($source['url']);
            if (!$url || !isset($url['host']) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass']) || !in_array($source['priority'], ['A', 'B', 'C'], true) || !is_int($source['limit']) || $source['limit'] < 1 || $source['limit'] > 100) {
                throw new InvalidArgumentException('Invalid source configuration.');
            }
            $existing = $db->fetch('SELECT id, url FROM sources WHERE name = ?', $source['name']);
            if ($existing !== null && $existing['url'] !== $source['url']) {
                throw new InvalidArgumentException('Existing source has another URL; review instead of overwriting.');
            }
            $now = new DateTimeImmutable();
            if ($existing === null) {
                $db->query('INSERT INTO sources', ['name' => $source['name'], 'url' => $source['url'], 'source_type' => 'browser', 'priority' => $source['priority'], 'active' => true, 'access_requirement' => 'unknown', 'adapter_capabilities' => '{"executor":"codex-chrome","protocol":1}', 'created_at' => $now, 'updated_at' => $now]);
                $sourceId = (int) $db->getInsertId();
            } else { $sourceId = (int) $existing['id']; }
            if ($db->fetchField('SELECT id FROM source_search_definitions WHERE source_id = ? AND name = ? AND version = ?', $sourceId, $source['definition'], $source['version']) === null) {
                $db->query('INSERT INTO source_search_definitions', ['source_id' => $sourceId, 'name' => $source['definition'], 'version' => $source['version'], 'query_text' => $source['query'], 'filters_json' => json_encode($source['filters'], JSON_THROW_ON_ERROR), 'pagination_strategy' => 'visible_next_or_end_with_result_limit', 'result_limit' => $source['limit'], 'active' => true, 'created_at' => $now]);
            }
            $sourceIds[] = $sourceId;
        }
        $container->getByType(App\Infrastructure\AuditLogger::class)->record('executor.configuration_imported', $owner, ['profile_id' => $profile, 'source_ids' => $sourceIds, 'search_started' => false]);
        return ['profile_id' => $profile, 'source_ids' => $sourceIds, 'search_started' => false];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}
if ($mode === 'revoke' && isset($argv[2])) {
    $tokenId = filter_var($argv[2], FILTER_VALIDATE_INT);
    if (!is_int($tokenId) || $db->fetchField('SELECT id FROM api_access_tokens WHERE id = ? AND created_by_user_id = ?', $tokenId, $owner) === null) {
        exit(2);
    }
    $credentials->revokeToken($owner, $tokenId);
    echo "Executor token revoked; history preserved.\n";
    exit(0);
}
if ($mode !== 'create') {
    exit(2);
}
$result = $db->transaction(function () use ($db, $credentials, $owner): array {
    $name = 'Codex Desktop executor';
    $existing = $db->fetch('SELECT id, public_identifier FROM api_clients WHERE name = ? AND client_type = ? AND created_by_user_id = ? AND revoked_at IS NULL', $name, 'runner', $owner);
    if ($existing !== null) {
        throw new RuntimeException('Executor already exists; rotate its credential explicitly, do not create another device.');
    }
    $client = $credentials->createClient($owner, $name, 'runner');
    $db->query('INSERT INTO runner_devices', ['api_client_id' => $client->id, 'public_identifier' => $client->publicIdentifier, 'name' => $name, 'device_status' => 'inactive', 'created_at' => new DateTimeImmutable()]);
    $token = $credentials->issueToken($owner, $client->id, ['search:execute'], new DateTimeImmutable('+30 days'));
    return ['token_id' => $token->id, 'client_id' => $client->id, 'token' => $token->token, 'expires_at' => $token->expiresAt->format(DATE_ATOM)];
});
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
