<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Opportunity\OpportunityJsonMapper;
use App\Opportunity\ProjectKindInput;
use App\Opportunity\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class ProjectKindInputTest extends TestCase
{
    public function testOmissionRetainsHistoryAndExplicitNullRepresentsUnknown(): void
    {
        $mapper = new OpportunityJsonMapper(new UrlNormalizer());
        self::assertNull($mapper->map('{"url":"https://example.test/project","originalTitle":"Project","originalText":"Description"}')[0]->projectKinds);
        self::assertNull(ProjectKindInput::fromPayload(['values' => null])?->values);
    }

    public function testMapsEvidenceBasedCombination(): void
    {
        $input = ProjectKindInput::fromPayload([
            'values' => ['takeover', 'prototype_to_production'],
            'reason' => 'Existing prototype needs a production handover.',
            'confidence' => 0.8,
            'verifiedAt' => '2026-09-18T10:00:00Z',
        ]);
        self::assertInstanceOf(ProjectKindInput::class, $input);
        self::assertSame(['takeover', 'prototype_to_production'], $input->values);
        self::assertSame(0.8, $input->confidence);
    }

    public function testKnownClassificationRequiresEvidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectKindInput::fromPayload(['values' => ['new_build']]);
    }

    public function testRejectsDuplicateOrUnsupportedValues(): void
    {
        foreach ([['takeover', 'takeover'], ['unsupported']] as $values) {
            try {
                new ProjectKindInput($values, 'Evidence', 0.8, new \DateTimeImmutable());
                self::fail('Neplatná klasifikace měla být odmítnuta.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
