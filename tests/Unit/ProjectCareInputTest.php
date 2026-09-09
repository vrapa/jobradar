<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Opportunity\ProjectCareInput;
use App\Opportunity\OpportunityJsonMapper;
use App\Opportunity\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class ProjectCareInputTest extends TestCase
{
    public function testUnknownAndOldPayloadRemainCompatible(): void
    {
        self::assertNull(ProjectCareInput::fromPayload(null));
        self::assertNull(ProjectCareInput::fromPayload(['value' => null])?->value);
        $mapper = new OpportunityJsonMapper(new UrlNormalizer());
        self::assertNull($mapper->map('{"url":"https://example.test/offer","originalTitle":"PHP","originalText":"Description"}')[0]->projectCare);
    }

    public function testKnownClassificationRequiresEvidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectCareInput::fromPayload(['value' => true]);
    }

    public function testRejectsNumericBoolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectCareInput::fromPayload(['value' => 0]);
    }

    public function testRejectsImpossibleCalendarDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectCareInput::fromPayload(['value' => true, 'reason' => 'Evidence', 'confidence' => 0.8, 'verifiedAt' => '2026-02-30T12:00:00Z']);
    }
}
