<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Action\ActionItemService;
use App\Bootstrap;
use App\Opportunity\OpportunityImport;
use App\Opportunity\OpportunityImportService;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ReviewOpportunitiesTest extends TestCase
{
    public function testSummaryEligibilityDeduplicationAndReplay(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $db = $container->getByType(Connection::class);
        $actions = $container->getByType(ActionItemService::class);
        $imports = $container->getByType(OpportunityImportService::class);
        try {
            $db->transaction(function () use ($db, $actions, $imports): void {
                $unique = bin2hex(random_bytes(8));
                $now = new \DateTimeImmutable();
                $db->query('INSERT INTO users', ['email' => $unique . '@example.test', 'display_name' => 'Synthetic review test', 'password_hash' => 'unusable', 'role' => 'admin', 'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now]);
                $user = (int) $db->getInsertId();
                $db->query('INSERT INTO sources', ['name' => 'Synthetic review ' . $unique, 'url' => 'https://example.test/' . $unique, 'source_type' => 'public_api', 'priority' => 'A', 'active' => true, 'access_requirement' => 'public', 'adapter_capabilities' => '{}', 'created_at' => $now, 'updated_at' => $now]);
                $source = (int) $db->getInsertId();
                $offer = $imports->import(new OpportunityImport('https://example.test/review/' . $unique, 'Synthetic review offer', 'Synthetic content.'))->opportunityId;
                $makeRun = function (string $status, ?string $result) use ($db, $user, $source, $offer, $now): int {
                    $db->query('INSERT INTO search_requests', ['requested_by_user_id' => $user, 'idempotency_key_hash' => hash('sha256', random_bytes(16)), 'requested_at' => $now]);
                    $request = (int) $db->getInsertId();
                    $db->query('INSERT INTO search_runs', ['search_request_id' => $request, 'run_status' => $status, 'started_at' => $now]);
                    $run = (int) $db->getInsertId();
                    $db->query('INSERT INTO search_run_sources', ['search_run_id' => $run, 'source_id' => $source]);
                    $runSource = (int) $db->getInsertId();
                    if ($result !== null) {
                        $db->query('INSERT INTO search_run_opportunities', ['search_run_id' => $run, 'search_run_source_id' => $runSource, 'opportunity_id' => $offer, 'processing_result' => $result, 'created_at' => $now]);
                    }
                    return $run;
                };
                foreach ([null, 'updated', 'duplicate', 'rejected'] as $result) {
                    $actions->reviewFinishedRun($makeRun('complete', $result));
                    self::assertCount(0, $actions->listOpen($user));
                }
                $running = $makeRun('running', 'created');
                $actions->reviewFinishedRun($running);
                self::assertCount(0, $actions->listOpen($user));
                $db->query('UPDATE opportunities SET archived_at = ? WHERE id = ?', $now, $offer);
                $actions->reviewFinishedRun($makeRun('partial', 'created'));
                self::assertCount(0, $actions->listOpen($user));
                $db->query('UPDATE opportunities SET archived_at = NULL WHERE id = ?', $offer);
                $db->query('INSERT INTO user_opportunity_state', ['user_id' => $user, 'opportunity_id' => $offer, 'decision' => 'react', 'updated_at' => $now]);
                $actions->reviewFinishedRun($makeRun('complete', 'created'));
                self::assertCount(0, $actions->listOpen($user));
                $db->query("UPDATE user_opportunity_state SET decision = 'undecided' WHERE user_id = ? AND opportunity_id = ?", $user, $offer);
                $partial = $makeRun('partial', 'created');
                $actions->reviewFinishedRun($partial);
                $items = $actions->listOpen($user, true);
                self::assertCount(1, $items);
                self::assertSame('review_opportunities', $items[0]['action_type']);
                self::assertNull($items[0]['opportunity_id']);
                self::assertNull($items[0]['due_at']);
                self::assertStringEndsWith('/?decision=undecided', $items[0]['action_url']);
                $second = $makeRun('complete', 'created');
                $actions->reviewFinishedRun($second);
                self::assertCount(1, $actions->listOpen($user));
                $actions->complete($user, $items[0]['id']);
                $actions->reviewFinishedRun($partial);
                $actions->reviewFinishedRun($second);
                self::assertCount(0, $actions->listOpen($user));
                self::assertSame('undecided', $db->fetchField('SELECT decision FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?', $user, $offer));
                $actions->reviewFinishedRun($makeRun('complete', 'created'));
                self::assertCount(1, $actions->listOpen($user));
                self::assertNotSame($items[0]['id'], $actions->listOpen($user)[0]['id']);
                throw new \DomainException('rollback-synthetic-review');
            });
        } catch (\DomainException $exception) {
            self::assertSame('rollback-synthetic-review', $exception->getMessage());
        }
    }
}
