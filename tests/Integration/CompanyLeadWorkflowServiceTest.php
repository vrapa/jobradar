<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Action\ActionItemService;
use App\Application\ApplicationWorkflowService;
use App\Bootstrap;
use App\CompanyLead\CompanyLeadWorkflowService;
use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Opportunity\CompanyLeadInput;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityQueryService;
use App\Opportunity\OpportunityType;
use App\Opportunity\OpportunityTypeService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class CompanyLeadWorkflowServiceTest extends TestCase
{
    public function testTypesLeadWorkflowTodoistBoundaryAndSafeConversion(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $db = $container->getByType(Connection::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $queries = $container->getByType(OpportunityQueryService::class);
        $leads = $container->getByType(CompanyLeadWorkflowService::class);
        $types = $container->getByType(OpportunityTypeService::class);
        $actions = $container->getByType(ActionItemService::class);
        $decisions = $container->getByType(OpportunityDecisionService::class);
        $applications = $container->getByType(ApplicationWorkflowService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = null;
        $opportunityIds = [];
        $companyNames = [];

        try {
            $now = new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'));
            $db->query('INSERT INTO users', [
                'email' => 'lead-' . $unique . '@example.test',
                'display_name' => 'Synthetic company lead user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $db->getInsertId();

            $offer = $imports->import(new OpportunityImport(
                'https://offers.example.test/' . $unique,
                'Synthetic offer',
                'Synthetic offer text.',
            ), $userId)->opportunityId;
            $opportunityIds[] = $offer;
            self::assertSame(OpportunityType::OFFER, $queries->getDetail($offer, $userId)?->opportunityType);

            $companyNames[] = $leadCompany = 'Synthetic Lead Company ' . $unique;
            $lead = $imports->import(new OpportunityImport(
                url: 'https://network.example.test/' . $unique,
                originalTitle: 'Synthetic networking contact',
                originalText: 'Synthetic context without a confirmed job offer.',
                companyName: $leadCompany,
                opportunityType: OpportunityType::COMPANY_LEAD,
                companyLead: new CompanyLeadInput(
                    'Synthetic Contact',
                    'Engineering contact',
                    'LinkedIn',
                    'https://network.example.test/profile/' . $unique,
                    'Synthetic outreach context.',
                ),
            ), $userId)->opportunityId;
            $opportunityIds[] = $lead;

            $tender = $imports->import(new OpportunityImport(
                url: 'https://tenders.example.test/' . $unique,
                originalTitle: 'Synthetic tender',
                originalText: 'Synthetic tender text.',
                opportunityType: OpportunityType::TENDER,
            ), $userId)->opportunityId;
            $opportunityIds[] = $tender;

            self::assertContains($offer, array_map(static fn ($item): int => $item->id, $queries->listCurrent($userId, OpportunityType::OFFER)));
            self::assertContains($lead, array_map(static fn ($item): int => $item->id, $queries->listCurrent($userId, OpportunityType::COMPANY_LEAD)));
            self::assertContains($tender, array_map(static fn ($item): int => $item->id, $queries->listCurrent($userId, OpportunityType::TENDER)));
            $leadDetail = $queries->getDetail($lead, $userId);
            self::assertNotNull($leadDetail);
            self::assertNotNull($leadDetail->companyLead);
            self::assertSame('Synthetic Contact', $leadDetail->companyLead['contact_name'] ?? null);
            self::assertFalse(array_any($queries->listReactionQueue($userId), static fn ($item): bool => $item->id === $lead));
            try {
                $decisions->setManualDecision($userId, $lead, 0, OpportunityDecision::React);
                self::fail('Firemní lead nesmí používat rozhodnutí Reagovat.');
            } catch (\InvalidArgumentException) {
            }

            $prepare = $actions->create($userId, $lead, 'prepare_outreach', 'Připravit syntetické oslovení');
            $prepared = $leads->record($userId, $lead, [
                'event' => 'prepared',
                'expected_lock_version' => 0,
                'idempotency_key' => 'lead-prepared-' . $unique,
                'reference' => 'private/draft-synthetic.md',
                'occurred_at' => $now->format(DATE_ATOM),
            ]);
            self::assertSame('awaiting_approval', $prepared['workflow_status']);
            self::assertSame(0, (int) $db->fetchField('SELECT COUNT(*) FROM application_events WHERE opportunity_id = ?', $lead));

            $contactedInput = [
                'event' => 'contacted',
                'expected_lock_version' => 1,
                'idempotency_key' => 'lead-contacted-' . $unique,
                'reference' => 'Verified synthetic sent message ID',
                'occurred_at' => $now->format(DATE_ATOM),
                'channel' => 'LinkedIn',
                'approval_reference' => 'Explicit approval in synthetic task',
                'follow_up_at' => $now->modify('+7 days')->format(DATE_ATOM),
                'complete_action_item_ids' => [$prepare],
            ];
            foreach (['approval_reference', 'follow_up_at', 'channel'] as $required) {
                $invalid = $contactedInput;
                unset($invalid[$required]);
                try {
                    $leads->record($userId, $lead, $invalid, 'assistant');
                    self::fail('Schválené oslovení musí vyžadovat všechny doklady.');
                } catch (\InvalidArgumentException) {
                }
            }
            $contacted = $leads->record($userId, $lead, $contactedInput, 'assistant');
            self::assertSame('awaiting_response', $contacted['workflow_status']);
            self::assertFalse($contacted['sent_by_this_operation']);
            self::assertSame('completed', $db->fetchField('SELECT status FROM action_items WHERE id = ?', $prepare));
            self::assertSame('open', $db->fetchField('SELECT status FROM action_items WHERE id = ?', $contacted['follow_up_action_item_id']));

            $response = $leads->record($userId, $lead, [
                'event' => 'response_received',
                'expected_lock_version' => 2,
                'idempotency_key' => 'lead-response-' . $unique,
                'reference' => 'Verified synthetic response ID',
                'occurred_at' => $now->format(DATE_ATOM),
            ]);
            self::assertSame('response_received', $response['workflow_status']);
            self::assertSame('completed', $db->fetchField('SELECT status FROM action_items WHERE id = ?', $contacted['follow_up_action_item_id']));
            $reply = $actions->create($userId, $lead, 'reply', 'Odpovědět na syntetickou reakci');
            self::assertSame('reply', $db->fetchField('SELECT action_type FROM action_items WHERE id = ?', $reply));

            $leads->record($userId, $lead, [
                'event' => 'closed',
                'expected_lock_version' => 3,
                'idempotency_key' => 'lead-closed-' . $unique,
                'reference' => 'Synthetic contact closed',
                'occurred_at' => $now->format(DATE_ATOM),
            ]);
            $late = $leads->record($userId, $lead, [
                'event' => 'response_received',
                'expected_lock_version' => 4,
                'idempotency_key' => 'lead-late-response-' . $unique,
                'reference' => 'Verified late synthetic response ID',
                'occurred_at' => $now->format(DATE_ATOM),
            ]);
            self::assertSame('response_received', $late['workflow_status']);

            $todoistLead = $imports->import(new OpportunityImport(
                url: 'https://network.example.test/todoist/' . $unique,
                originalTitle: 'Synthetic Todoist boundary lead',
                originalText: 'Synthetic context.',
                opportunityType: OpportunityType::COMPANY_LEAD,
            ), $userId)->opportunityId;
            $opportunityIds[] = $todoistLead;
            $todoistAction = $actions->create($userId, $todoistLead, 'follow_up', 'Zkontrolovat syntetickou odpověď');
            $actions->linkExternalTask($userId, $todoistAction, 'todoist', 'synthetic-lead-' . $unique, null);
            $actions->complete($userId, $todoistAction, 'assistant');
            self::assertSame('new', $leads->state($userId, $todoistLead)['workflow_status']);
            self::assertSame(0, (int) $db->fetchField('SELECT COUNT(*) FROM company_lead_events WHERE opportunity_id = ?', $todoistLead));

            $convert = $imports->import(new OpportunityImport(
                'https://offers.example.test/convert/' . $unique,
                'Synthetic convertible offer',
                'Synthetic source version.',
            ), $userId)->opportunityId;
            $opportunityIds[] = $convert;
            $preservedAction = $actions->create($userId, $convert, 'other', 'Preserved synthetic task');
            $decisions->setManualDecision($userId, $convert, 0, OpportunityDecision::React);
            $sourceCount = (int) $db->fetchField('SELECT COUNT(*) FROM opportunity_sources WHERE opportunity_id = ?', $convert);
            $versionCount = (int) $db->fetchField('SELECT COUNT(*) FROM source_versions WHERE opportunity_id = ?', $convert);
            $converted = $types->convertOfferToCompanyLead($userId, $convert, 1, new CompanyLeadInput(channel: 'e-mail'));
            self::assertSame(OpportunityType::COMPANY_LEAD, $converted['opportunity_type']);
            self::assertSame(2, $converted['lock_version']);
            self::assertSame($sourceCount, (int) $db->fetchField('SELECT COUNT(*) FROM opportunity_sources WHERE opportunity_id = ?', $convert));
            self::assertSame($versionCount, (int) $db->fetchField('SELECT COUNT(*) FROM source_versions WHERE opportunity_id = ?', $convert));
            self::assertSame('open', $db->fetchField('SELECT status FROM action_items WHERE id = ?', $preservedAction));
            self::assertSame(1, (int) $db->fetchField('SELECT COUNT(*) FROM opportunity_type_history WHERE opportunity_id = ?', $convert));
            $convertedDetail = $queries->getDetail($convert, $userId);
            self::assertNotNull($convertedDetail);
            self::assertSame(OpportunityDecision::Undecided, $convertedDetail->decisionState->decision);
            self::assertFalse(array_any($queries->listReactionQueue($userId), static fn ($item): bool => $item->id === $convert));

            $decisions->setManualDecision($userId, $offer, 0, OpportunityDecision::React);
            $submitted = $applications->record($userId, $offer, [
                'event' => 'submitted',
                'expected_lock_version' => 1,
                'idempotency_key' => 'offer-submitted-' . $unique,
                'reference' => 'Verified synthetic application receipt',
                'occurred_at' => $now->format(DATE_ATOM),
                'channel' => 'email',
                'approval_reference' => 'Explicit synthetic approval',
                'follow_up_at' => $now->modify('+7 days')->format(DATE_ATOM),
                'complete_action_item_ids' => [],
            ]);
            self::assertSame('awaiting_response', $submitted['workflow_status']);
            try {
                $types->convertOfferToCompanyLead($userId, $offer, 1);
                self::fail('Nabídku s historií žádosti nelze převést.');
            } catch (\InvalidArgumentException) {
            }
            $offerDetail = $queries->getDetail($offer, $userId);
            self::assertSame(OpportunityType::OFFER, $offerDetail->opportunityType);
            self::assertSame('awaiting_response', $offerDetail->decisionState->workflowStatus);
        } finally {
            if ($userId !== null) {
                $db->query('DELETE task FROM external_tasks task JOIN action_items item ON item.id = task.action_item_id WHERE item.user_id = ?', $userId);
                $db->query('DELETE FROM company_lead_events WHERE user_id = ?', $userId);
                $db->query('DELETE FROM company_lead_states WHERE user_id = ?', $userId);
                $db->query('DELETE FROM application_events WHERE user_id = ?', $userId);
                $db->query('DELETE FROM action_items WHERE user_id = ?', $userId);
                $db->query('DELETE FROM opportunity_decision_history WHERE user_id = ?', $userId);
                $db->query('DELETE FROM user_opportunity_state WHERE user_id = ?', $userId);
                $db->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
            }
            foreach ($opportunityIds as $opportunityId) {
                $db->query('DELETE FROM opportunity_type_history WHERE opportunity_id = ?', $opportunityId);
                $db->query("DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?", (string) $opportunityId);
                $db->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $db->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $db->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $db->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
            }
            foreach ($companyNames as $companyName) {
                $db->query('DELETE FROM companies WHERE normalized_name = ?', mb_strtolower($companyName));
            }
            if ($userId !== null) {
                $db->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }
}
