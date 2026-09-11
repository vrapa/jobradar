<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Decision\DecisionQueryService;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityQueryService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class OpportunityDecisionServiceTest extends TestCase
{
    public function testManualDecisionsAreReversibleHistorizedAndNeverSubmitted(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $database = $container->getByType(Connection::class);
        $imports = $container->getByType(OpportunityImportService::class);
        $service = $container->getByType(OpportunityDecisionService::class);
        $queries = $container->getByType(OpportunityQueryService::class);
        $decisionQueries = $container->getByType(DecisionQueryService::class);
        $unique = bin2hex(random_bytes(8));
        $userId = null;
        $opportunityId = null;

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $database->query('INSERT INTO users', [
                'email' => $unique . '@example.test',
                'display_name' => 'Synthetic decision user',
                'password_hash' => password_hash($unique, PASSWORD_DEFAULT),
                'role' => 'admin',
                'locale' => 'cs_CZ',
                'timezone' => 'Europe/Prague',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $database->getInsertId();
            $opportunityId = $imports->import(new OpportunityImport(
                'https://jobs.example.test/decision/' . $unique,
                'Synthetic decision offer',
                'Synthetic text.',
            ))->opportunityId;

            $react = $service->setManualDecision($userId, $opportunityId, 0, OpportunityDecision::React);
            self::assertSame(OpportunityDecision::Undecided, $react->previousDecision);
            self::assertSame(1, $react->lockVersion);
            self::assertTrue($react->changed);
            self::assertTrue($this->listContains($queries, $userId, $opportunityId));
            self::assertTrue(array_any(
                $queries->listReactionQueue($userId),
                static fn ($item): bool => $item->id === $opportunityId && $item->workflowStatus === 'none',
            ));
            self::assertSame('none', $database->fetchField(
                'SELECT workflow_status FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));
            $actions = $container->getByType(\App\Action\ActionItemService::class);
            $plan = $actions->listOpen($userId)[0];
            self::assertSame('prepare_applications', $plan['action_type']);
            self::assertNull($plan['opportunity_id']);
            self::assertStringEndsWith('/nabidky/k-reakci', $plan['action_url']);
            self::assertSame($plan['id'], $actions->create($userId, null, 'prepare_applications', 'Another summary'));
            $same = $service->setManualDecision($userId, $opportunityId, 1, OpportunityDecision::React);
            self::assertFalse($same->changed);
            self::assertSame(1, $same->lockVersion);

            self::assertCount(1, $actions->listOpen($userId));
            $actions->complete($userId, $plan['id']);
            $service->setManualDecision($userId, $opportunityId, 1, OpportunityDecision::React);
            self::assertCount(0, $actions->listOpen($userId));

            $hidden = $service->setManualDecision(
                $userId,
                $opportunityId,
                1,
                OpportunityDecision::Uninteresting,
                'low_rate',
                'Syntetická poznámka.',
            );
            self::assertSame(2, $hidden->lockVersion);
            self::assertFalse($this->listContains($queries, $userId, $opportunityId));
            self::assertFalse(array_any(
                $queries->listReactionQueue($userId),
                static fn ($item): bool => $item->id === $opportunityId,
            ));
            self::assertTrue(array_any(
                $queries->listUninteresting($userId),
                static fn ($item): bool => $item->id === $opportunityId,
            ));
            $undo = $service->setManualDecision($userId, $opportunityId, 2, OpportunityDecision::Undecided);
            self::assertSame(3, $undo->lockVersion);
            self::assertTrue($this->listContains($queries, $userId, $opportunityId));
            self::assertFalse(array_any(
                $queries->listUninteresting($userId),
                static fn ($item): bool => $item->id === $opportunityId,
            ));
            self::assertSame(3, (int) $database->fetchField(
                'SELECT COUNT(*) FROM opportunity_decision_history WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));
            self::assertSame('undecided', $database->fetchField(
                'SELECT decision FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));
            $history = $decisionQueries->history($userId, $opportunityId);
            self::assertCount(3, $history);
            self::assertSame(OpportunityDecision::Undecided, $history[0]->newDecision);
            self::assertSame(OpportunityDecision::Uninteresting, $history[1]->newDecision);
            self::assertSame('Nízká sazba', $history[1]->reasonLabel);
            self::assertSame('Syntetická poznámka.', $history[1]->note);

            $assistant = $service->setAssistantDecision(
                $userId,
                $opportunityId,
                3,
                OpportunityDecision::React,
            );
            self::assertSame(4, $assistant->lockVersion);
            self::assertCount(1, $actions->listOpen($userId));
            self::assertNotSame($plan['id'], $actions->listOpen($userId)[0]['id']);
            $assistantHistory = $decisionQueries->history($userId, $opportunityId);
            self::assertSame('assistant', $assistantHistory[0]->actorType);
            self::assertSame('none', $database->fetchField(
                'SELECT workflow_status FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                $userId,
                $opportunityId,
            ));

            $this->expectException(OpportunityConflictException::class);
            $service->setManualDecision($userId, $opportunityId, 1, OpportunityDecision::React);
        } finally {
            if ($opportunityId !== null) {
                $database->query('DELETE FROM opportunity_decision_history WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM user_opportunity_state WHERE opportunity_id = ?', $opportunityId);
                $database->query(
                    "DELETE FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.opportunity_id')) = ?",
                    (string) $opportunityId,
                );
                $database->query('UPDATE opportunities SET current_source_version_id = NULL WHERE id = ?', $opportunityId);
                $database->query('DELETE FROM source_versions WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunity_sources WHERE opportunity_id = ?', $opportunityId);
                $database->query('DELETE FROM opportunities WHERE id = ?', $opportunityId);
            }
            if ($userId !== null) {
                $database->query('DELETE FROM audit_log WHERE actor_user_id = ?', $userId);
                $database->query('DELETE FROM action_items WHERE user_id = ?', $userId);
                $database->query('DELETE FROM users WHERE id = ?', $userId);
            }
        }
    }

    private function listContains(OpportunityQueryService $queries, int $userId, int $opportunityId): bool
    {
        return array_any(
            $queries->listCurrent($userId),
            static fn ($item): bool => $item->id === $opportunityId,
        );
    }
}
