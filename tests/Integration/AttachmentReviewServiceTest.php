<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Action\ActionItemService;
use App\Bootstrap;
use App\Opportunity\AttachmentReviewService;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityJsonMapper;
use Nette\Database\Connection;
use PHPUnit\Framework\TestCase;

final class AttachmentReviewServiceTest extends TestCase
{
    public function testImportDeduplicationResolutionAndTaskIndependence(): void
    {
        if (getenv('DB_HOST') === false) { self::markTestSkipped('Vyžaduje testovací databázi.'); }
        $c = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $db = $c->getByType(Connection::class);
        $db->transaction(function () use ($c, $db): void {
            $now = new \DateTimeImmutable();
            $db->query('INSERT INTO users', ['email' => uniqid() . '@example.test', 'display_name' => 'Attachments', 'password_hash' => 'unusable', 'role' => 'admin', 'locale' => 'cs_CZ', 'timezone' => 'Europe/Prague', 'created_at' => $now, 'updated_at' => $now]);
            $user = (int) $db->getInsertId();
            $mapper = $c->getByType(OpportunityJsonMapper::class);
            $importer = $c->getByType(OpportunityImportService::class);
            $reviews = $c->getByType(AttachmentReviewService::class);
            $actions = $c->getByType(ActionItemService::class);
            $attachment = ['key' => 'brief-v1', 'name' => '<script>brief</script>.pdf', 'status' => 'pending', 'reason' => 'Stažení skončilo chybou.', 'questions' => 'Rozsah práce?', 'observedAt' => $now->format(DATE_ATOM)];
            $payload = ['url' => 'https://example.test/' . uniqid(), 'originalTitle' => 'Offer', 'originalText' => 'Source text', 'incomplete' => true, 'attachmentReviews' => [$attachment]];
            $import = fn (array $p) => $importer->import($mapper->map(json_encode($p, JSON_THROW_ON_ERROR))[0], $user);
            $result = $import($payload);
            $import($payload);
            $records = $reviews->listForOpportunity($user, $result->opportunityId);
            self::assertCount(1, $records);
            self::assertCount(1, $actions->listOpen($user));
            self::assertSame([], $reviews->listForOpportunity($user + 9999, $result->opportunityId));
            $id = (int) $records[0]['action_item_id'];
            $actions->complete($user, $id, 'assistant');
            $import($payload);
            self::assertSame('pending', $reviews->listForOpportunity($user, $result->opportunityId)[0]['status']);
            self::assertCount(1, $actions->listForOpportunity($user, $result->opportunityId));
            self::assertSame(1, (int) $db->fetchField('SELECT incomplete FROM source_versions WHERE id=?', $result->sourceVersionId));

            $resolved = [...$attachment, 'status' => 'reviewed', 'reason' => 'Přečteno v projektu Práce.', 'findings' => 'Rozsah 10 hodin týdně.', 'evidence' => 'brief.pdf strana 2', 'expectedRevision' => 1];
            $import([...$payload, 'attachmentReviews' => [$resolved]]);
            $import([...$payload, 'attachmentReviews' => [$resolved]]);
            self::assertSame(2, (int) $reviews->listForOpportunity($user, $result->opportunityId)[0]['revision']);
            $import($payload);
            self::assertSame('reviewed', $reviews->listForOpportunity($user, $result->opportunityId)[0]['status']);
            self::assertSame(0, (int) $db->fetchField('SELECT COUNT(*) FROM user_opportunity_state WHERE opportunity_id=?', $result->opportunityId));
            try { $import([...$payload, 'attachmentReviews' => [[...$resolved, 'findings' => 'Stale overwrite']]]); self::fail('Stará revize nesmí přepsat zjištění.'); }
            catch (\InvalidArgumentException) {}
            $import([...$payload, 'attachmentReviews' => [[...$attachment, 'key' => 'expired-brief', 'status' => 'not_needed', 'reason' => 'Poptávka je prokazatelně ukončená.']]]);
            self::assertCount(1, $actions->listForOpportunity($user, $result->opportunityId));
            self::assertCount(2, $reviews->listForOpportunity($user, $result->opportunityId));
        });
    }

    public function testIncompleteResolutionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \App\Opportunity\AttachmentReviewInput::parse([['key' => 'brief', 'name' => 'Brief', 'status' => 'reviewed', 'reason' => 'Staženo', 'observedAt' => '2026-09-10T07:00:00Z']]);
    }
}
