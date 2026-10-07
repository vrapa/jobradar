<?php

declare(strict_types=1);

// Trusted server administration, like executor-admin.php. Never exposed to MCP or the runner.
require dirname(__DIR__) . '/vendor/autoload.php';
$mode = $argv[1] ?? '';
$sourceId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!in_array($mode, ['status', 'set-active'], true) || $sourceId === false) {
    fwrite(STDERR, "Usage: source-admin.php status SOURCE_ID | set-active SOURCE_ID ACTOR_ID EXPECTED_VERSION 0|1 REASON\n");
    exit(2);
}
$container = (new App\Bootstrap(dirname(__DIR__)))->bootConsole();
$settings = $container->getByType(App\Search\SourceSettingsService::class);
$db = $container->getByType(Nette\Database\Connection::class);
try {
    if ($mode === 'set-active') {
        $actor = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $version = filter_var($argv[4] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $active = $argv[5] ?? '';
        if ($actor === false || $version === false || !in_array($active, ['0', '1'], true)) {
            throw new InvalidArgumentException('Invalid actor, expected version or activation state.');
        }
        $settings->setActive($actor, $sourceId, $version, $active === '1', $argv[6] ?? '');
    }
    $state = $settings->activationState($sourceId);
    $state['active'] = (bool) $state['active'];
    $state['lock_version'] = (int) $state['lock_version'];
    $state['history_counts'] = [];
    foreach (['source_search_definitions', 'source_access_states', 'search_request_sources', 'search_run_sources'] as $table) {
        $state['history_counts'][$table] = (int) $db->fetchField('SELECT COUNT(*) FROM ' . $table . ' WHERE source_id = ?', $sourceId);
    }
    $state['active_admin_ids'] = array_map(static fn ($row): int => (int) $row['id'], $db->fetchAll("SELECT id FROM users WHERE role = 'admin' AND deactivated_at IS NULL ORDER BY id"));
    $state['last_configuration_actor_id'] = $db->fetchField("SELECT actor_user_id FROM audit_log WHERE event_type IN ('source.priority_saved', 'source.plan_version_created', 'source.activation_saved') AND JSON_EXTRACT(context_json, '$.source_id') = ? ORDER BY id DESC LIMIT 1", $sourceId);
    $event = $db->fetch("SELECT id, actor_user_id, context_json, created_at FROM audit_log WHERE event_type = 'source.activation_saved' AND JSON_EXTRACT(context_json, '$.source_id') = ? ORDER BY id DESC LIMIT 1", $sourceId);
    $state['last_activation_event'] = $event === null ? null : (array) $event;
    echo json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}
