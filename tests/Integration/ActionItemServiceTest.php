<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Action\ActionItemService;
use App\Bootstrap;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ActionItemServiceTest extends TestCase
{
    public function testTodoistLinkAndCompletionNeverChangeDecisionOrApplicationState(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $service = $container->getByType(ActionItemService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = null;
        $opportunityId = null;
        $actionItemId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test',
                'display_name' => 'Synthetic action item user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $opportunityId = $imports->import(new OpportunityImport(
                'https://jobs.example.test/action-item/' . $unique,
                'Synthetic action item offer',
                'Synthetic text.',
            ))->opportunityId;

            $actionItemId = $service->create($userId, $opportunityId, 'reply', 'Odpovědět do termínu', dueAt: $now->modify('+1 day'));
            self::assertCount(1, $service->listOpen($userId, true));

            $service->linkExternalTask($userId, $actionItemId, 'todoist', 'todoist-' . $unique, 'https://app.todoist.com/app/task/' . $unique);
            self::assertCount(0, $service->listOpen($userId, true));
            self::assertSame('undecided', $database->fetchField(
                'SELECT COALESCE(decision, ?) FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                'undecided',
                $userId,
                $opportunityId,
            ) ?: 'undecided');

            $service->complete($userId, $actionItemId, 'assistant');
            self::assertSame('completed', $database->fetchField('SELECT status FROM action_items WHERE id = ?', $actionItemId));
            self::assertSame('none', $database->fetchField(
                'SELECT COALESCE(workflow_status, ?) FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                'none',
                $userId,
                $opportunityId,
            ) ?: 'none');
            self::assertSame(0, (int) $database->fetchField(
                "SELECT COUNT(*) FROM audit_log WHERE event_type = 'application.submitted' AND actor_user_id = ?",
                $userId,
            ));
        } finally {
            if ($actionItemId !== null) {
                $database->query('DELETE FROM external_tasks WHERE action_item_id = ?', $actionItemId);
                $database->query('DELETE FROM action_items WHERE id = ?', $actionItemId);
            }
            if ($opportunityId !== null) {
                $database->query('DELETE FROM user_opportunity_state WHERE opportunity_id = ?', $opportunityId);
                $database->query("DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.action_item_id')) = ?", (string) $opportunityId, (string) $actionItemId);
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
