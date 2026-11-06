<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Action\ActionItemService;
use App\Bootstrap;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityQueryService;
use App\Opportunity\OpportunityType;
use App\Tender\TenderWorkflowService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class TenderWorkflowServiceTest extends TestCase
{
    public function testTenderCanBeClosedIdempotentlyFilteredAndReopenedWithoutChangingOtherOpportunityTypes(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $queries = $container->getByType(OpportunityQueryService::class);
        $workflow = $container->getByType(TenderWorkflowService::class);
        $actions = $container->getByType(ActionItemService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = null;
        $opportunityIds = [];
        $actionItemIds = [];

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => 'tender-' . $unique . '@example.test',
                'display_name' => 'Synthetic tender owner',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();

            foreach ([OpportunityType::TENDER, OpportunityType::OFFER, OpportunityType::COMPANY_LEAD] as $type) {
                $opportunityIds[$type] = $imports->import(new OpportunityImport(
                    url: 'https://tender-workflow.example.test/' . $type . '/' . $unique,
                    originalTitle: 'Synthetic ' . $type,
                    originalText: 'Synthetic content for a tender workflow test.',
                    opportunityType: $type,
                ), $userId)->opportunityId;
            }
            $tenderId = $opportunityIds[OpportunityType::TENDER];
            $initialDetail = $queries->getDetail($tenderId, $userId);
            self::assertNotNull($initialDetail);
            self::assertSame('active', $initialDetail->tenderStatus);
            self::assertSame(0, $initialDetail->tenderLockVersion);
            $sourceVersionCount = (int) $database->fetchField('SELECT COUNT(*) FROM source_versions WHERE opportunity_id = ?', $tenderId);
            $opportunityLockVersion = (int) $database->fetchField('SELECT lock_version FROM opportunities WHERE id = ?', $tenderId);

            $openLinked = $actions->create($userId, $tenderId, 'follow_up', 'Synthetic linked open task');
            $actionItemIds[] = $openLinked;
            $actions->linkExternalTask($userId, $openLinked, 'todoist', 'synthetic-tender-' . $unique, 'https://app.todoist.com/app/task/synthetic-' . $unique);
            $openUnlinked = $actions->create($userId, $tenderId, 'verify_terms', 'Synthetic unlinked open task');
            $actionItemIds[] = $openUnlinked;
            $completed = $actions->create($userId, $tenderId, 'other', 'Synthetic completed task');
            $actionItemIds[] = $completed;
            $actions->complete($userId, $completed);
            $cancelled = $actions->create($userId, $tenderId, 'other', 'Synthetic already cancelled task');
            $actionItemIds[] = $cancelled;
            $actions->cancel($userId, $cancelled);

            $closeInput = [
                'event' => 'closed',
                'expected_lock_version' => 0,
                'idempotency_key' => 'tender-close-' . $unique,
            ];
            $closed = $workflow->record($userId, $tenderId, $closeInput);
            self::assertSame('closed', $closed['workflow_status']);
            self::assertSame(1, $closed['lock_version']);
            self::assertEqualsCanonicalizing([$openLinked, $openUnlinked], $closed['cancelled_action_item_ids']);
            self::assertFalse($closed['decision_changed']);
            self::assertFalse($closed['application_submitted']);
            self::assertSame('cancelled', $database->fetchField('SELECT status FROM action_items WHERE id = ?', $openLinked));
            self::assertSame('cancelled', $database->fetchField('SELECT status FROM action_items WHERE id = ?', $openUnlinked));
            self::assertSame('completed', $database->fetchField('SELECT status FROM action_items WHERE id = ?', $completed));
            self::assertSame('cancelled', $database->fetchField('SELECT status FROM action_items WHERE id = ?', $cancelled));
            self::assertSame(OpportunityType::TENDER, $database->fetchField('SELECT opportunity_type FROM opportunities WHERE id = ?', $tenderId));
            self::assertSame($sourceVersionCount, (int) $database->fetchField('SELECT COUNT(*) FROM source_versions WHERE opportunity_id = ?', $tenderId));
            self::assertSame($opportunityLockVersion, (int) $database->fetchField('SELECT lock_version FROM opportunities WHERE id = ?', $tenderId));

            $linkedForSync = $actions->listLinked($userId);
            self::assertTrue(array_any($linkedForSync, static fn (array $item): bool => $item['id'] === $openLinked && $item['status'] === 'cancelled' && $item['synced_status'] === 'open'));

            self::assertEquals($closed, $workflow->record($userId, $tenderId, $closeInput));
            self::assertSame(1, (int) $database->fetchField('SELECT COUNT(*) FROM tender_events WHERE user_id = ? AND opportunity_id = ?', $userId, $tenderId));
            self::assertSame(2, (int) $database->fetchField("SELECT COUNT(*) FROM audit_log WHERE event_type = 'action_item.cancelled' AND actor_user_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.origin')) = 'system'", $userId));

            try {
                $workflow->record($userId, $tenderId, [
                    'event' => 'reopened',
                    'expected_lock_version' => 0,
                    'idempotency_key' => 'tender-stale-' . $unique,
                ]);
                self::fail('Zastaralý lock_version musí být odmítnut.');
            } catch (OpportunityConflictException) {
            }

            self::assertNotContains($tenderId, array_map(static fn ($item): int => $item->id, $queries->listCurrent($userId, OpportunityType::TENDER)));
            $closedItems = $queries->listCurrent($userId, OpportunityType::TENDER, 'closed');
            self::assertContains($tenderId, array_map(static fn ($item): int => $item->id, $closedItems));
            self::assertSame('closed', array_values(array_filter($closedItems, static fn ($item): bool => $item->id === $tenderId))[0]->tenderStatus);
            self::assertContains($tenderId, array_map(static fn ($item): int => $item->id, $queries->listCurrent($userId, OpportunityType::TENDER, 'all')));

            foreach ([OpportunityType::OFFER, OpportunityType::COMPANY_LEAD] as $forbiddenType) {
                try {
                    $workflow->record($userId, $opportunityIds[$forbiddenType], [
                        'event' => 'closed',
                        'expected_lock_version' => 0,
                        'idempotency_key' => 'tender-forbidden-' . $forbiddenType . '-' . $unique,
                    ]);
                    self::fail('Tender operace nesmí přijmout typ ' . $forbiddenType . '.');
                } catch (\InvalidArgumentException) {
                }
            }

            $reopened = $workflow->record($userId, $tenderId, [
                'event' => 'reopened',
                'expected_lock_version' => 1,
                'idempotency_key' => 'tender-reopen-' . $unique,
            ]);
            self::assertSame('active', $reopened['workflow_status']);
            self::assertSame(2, $reopened['lock_version']);
            self::assertSame([], $reopened['cancelled_action_item_ids']);
            self::assertContains($tenderId, array_map(static fn ($item): int => $item->id, $queries->listCurrent($userId, OpportunityType::TENDER)));
            self::assertNotContains($tenderId, array_map(static fn ($item): int => $item->id, $queries->listCurrent($userId, OpportunityType::TENDER, 'closed')));
            self::assertCount(2, $workflow->history($userId, $tenderId));
        } finally {
            foreach ($actionItemIds as $actionItemId) {
                $database->query('DELETE FROM external_tasks WHERE action_item_id = ?', $actionItemId);
            }
            if (is_int($userId)) {
                $database->query('DELETE FROM tender_events WHERE user_id = ?', $userId);
                $database->query('DELETE FROM tender_states WHERE user_id = ?', $userId);
                $database->query('DELETE FROM action_items WHERE user_id = ?', $userId);
            }
            foreach ($opportunityIds as $opportunityId) {
                $database->query("DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?", (string) $opportunityId);
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM company_lead_details WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
            }
            if (is_int($userId)) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
