<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Action\ActionItemService;
use App\Application\ApplicationWorkflowService;
use App\Bootstrap;
use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityQueryService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ApplicationWorkflowServiceTest extends TestCase
{
    public function testPreparationSubmissionReplayAndResponseReconcileOnlyOwnedTasks(): void
    {
        if (getenv('DB_HOST') === false) { self::markTestSkipped('Integrační databáze není nakonfigurovaná.'); }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $db = $container->getByType(Connection::class);
        $workflow = $container->getByType(ApplicationWorkflowService::class);
        $actions = $container->getByType(ActionItemService::class);
        $queries = $container->getByType(OpportunityQueryService::class);
        $unique = bin2hex(random_bytes(8));
        $user = null;
        $offer = null;
        try {
            $now = new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'));
            $db->query('INSERT INTO users', ['email' => $unique . '@example.test', 'display_name' => 'Synthetic workflow user', 'password_hash' => password_hash($unique, PASSWORD_DEFAULT), 'role' => 'admin', 'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now]);
            $user = (int) $db->getInsertId();
            $offer = $container->getByType(OpportunityImportService::class)->import(new OpportunityImport('https://jobs.example.test/workflow/' . $unique, 'Synthetic workflow offer', 'Synthetic text.'))->opportunityId;
            $container->getByType(OpportunityDecisionService::class)->setManualDecision($user, $offer, 0, OpportunityDecision::React);
            $prep = $actions->create($user, $offer, 'other', 'Připravit reakci');
            $unrelated = $actions->create($user, $offer, 'verify_terms', 'Jiný otevřený krok');
            $actions->linkExternalTask($user, $prep, 'todoist', 'synthetic-' . $unique, null);
            $prepared = ['event' => 'prepared', 'expected_lock_version' => 1, 'idempotency_key' => 'prepared-' . $unique, 'reference' => 'Prace/draft.md', 'occurred_at' => $now->format(DATE_ATOM)];
            $result = $workflow->record($user, $offer, $prepared);
            self::assertSame('awaiting_approval', $result['workflow_status']);
            self::assertTrue(array_any($queries->listReactionQueue($user), static fn ($o): bool => $o->id === $offer));
            self::assertSame([], $queries->listAwaitingResponse($user));
            self::assertCount(2, $actions->listOpen($user));
            $submitted = ['event' => 'submitted', 'expected_lock_version' => 2, 'idempotency_key' => 'submitted-' . $unique, 'reference' => 'Verified sent email ID synthetic-1', 'occurred_at' => $now->format(DATE_ATOM), 'channel' => 'email', 'approval_reference' => 'Explicit approval of final text in synthetic task', 'follow_up_at' => $now->modify('+7 days')->format(DATE_ATOM), 'complete_action_item_ids' => [$prep]];
            foreach (['reference', 'approval_reference', 'follow_up_at'] as $required) {
                $bad = $submitted; unset($bad[$required]);
                try { $workflow->record($user, $offer, $bad); self::fail('Missing submission evidence must fail.'); } catch (\InvalidArgumentException) {}
            }
            $bad = $submitted; $bad['expected_lock_version'] = 1;
            try { $workflow->record($user, $offer, $bad); self::fail('Stale version must fail.'); } catch (OpportunityConflictException) {}
            // A nonexistent/foreign action must roll back the entire submission.
            $bad = $submitted; $bad['complete_action_item_ids'] = [$prep, PHP_INT_MAX];
            try { $workflow->record($user, $offer, $bad); self::fail('Foreign action must fail.'); } catch (\InvalidArgumentException) {}
            self::assertSame('open', $db->fetchField('SELECT status FROM action_items WHERE id=?', $prep));
            $sent = $workflow->record($user, $offer, $submitted, 'assistant');
            self::assertSame('awaiting_response', $sent['workflow_status']);
            self::assertFalse($sent['sent_by_this_operation']);
            self::assertEquals($sent, $workflow->record($user, $offer, $submitted, 'assistant'));
            self::assertCount(2, $workflow->history($user, $offer));
            self::assertFalse(array_any($queries->listReactionQueue($user), static fn ($o): bool => $o->id === $offer));
            self::assertTrue(array_any($queries->listAwaitingResponse($user), static fn ($o): bool => $o->id === $offer));
            self::assertSame('react', $queries->getDetail($offer, $user)?->decisionState->decision->value);
            self::assertSame('completed', $db->fetchField('SELECT status FROM action_items WHERE id=?', $prep));
            self::assertSame('open', $db->fetchField('SELECT status FROM action_items WHERE id=?', $unrelated));
            self::assertCount(1, $actions->listLinked($user));
            $actions->acknowledgeExternalStatus($user, $prep, 'completed');
            self::assertSame([], $actions->listLinked($user));
            self::assertSame('follow_up', $db->fetchField('SELECT action_type FROM action_items WHERE id=?', $sent['follow_up_action_item_id']));
            $bad = $submitted; $bad['reference'] = 'Different evidence';
            try { $workflow->record($user, $offer, $bad); self::fail('Reused key with different body must fail.'); } catch (OpportunityConflictException) {}
            $bad = $submitted; $bad['idempotency_key'] .= '-again'; $bad['expected_lock_version'] = 3;
            try { $workflow->record($user, $offer, $bad); self::fail('Second submission must not create duplicate follow-up.'); } catch (\InvalidArgumentException) {}
            $workflow->record($user, $offer, ['event' => 'response_received', 'expected_lock_version' => 3, 'idempotency_key' => 'response-' . $unique, 'reference' => 'Received message synthetic-2', 'occurred_at' => $now->format(DATE_ATOM)]);
            self::assertSame('completed', $db->fetchField('SELECT status FROM action_items WHERE id=?', $sent['follow_up_action_item_id']));
            self::assertSame([], $queries->listAwaitingResponse($user));
            self::assertSame('open', $db->fetchField('SELECT status FROM action_items WHERE id=?', $unrelated));
            $workflow->record($user, $offer, ['event' => 'closed', 'expected_lock_version' => 4, 'idempotency_key' => 'closed-' . $unique, 'reference' => 'Jednání dokončeno', 'occurred_at' => $now->format(DATE_ATOM)]);
            self::assertSame('closed', $queries->getDetail($offer, $user)->decisionState->workflowStatus);
        } finally {
            if ($user !== null) {
                $db->query('DELETE t FROM external_tasks t JOIN action_items a ON a.id=t.action_item_id WHERE a.user_id=?', $user);
                $db->query('DELETE FROM application_events WHERE user_id=?', $user);
                $db->query('DELETE FROM action_items WHERE user_id=?', $user);
                $db->query('DELETE FROM opportunity_decision_history WHERE user_id=?', $user);
                $db->query('DELETE FROM user_opportunity_state WHERE user_id=?', $user);
                $db->query('DELETE FROM audit_log WHERE actor_user_id=?', $user);
                $db->query('DELETE FROM users WHERE id=?', $user);
            }
            if ($offer !== null) {
                $db->query("DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id'))=?", (string) $offer);
                $db->query('UPDATE opportunities SET current_source_version_id=NULL WHERE id=?', $offer);
                $db->query('DELETE FROM source_versions WHERE opportunity_id=?', $offer);
                $db->query('DELETE FROM opportunity_sources WHERE opportunity_id=?', $offer);
                $db->query('DELETE FROM opportunities WHERE id=?', $offer);
            }
        }
    }
}
