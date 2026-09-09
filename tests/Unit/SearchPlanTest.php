<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Search\SearchPlan;
use App\Opportunity\CounterpartyInput;
use PHPUnit\Framework\TestCase;

final class SearchPlanTest extends TestCase
{
    public function testPlanKeepsOrderAndSharedLimitIsNotMultiplied(): void
    {
        $steps = SearchPlan::parse('[{"key":"care","name":"Care","query":"maintenance","limit":8},{"key":"php","name":"PHP","query":"PHP","limit":8}]', 10);
        self::assertSame(['care','php'], array_column($steps, 'key'));
        self::assertSame('keywords', $steps[0]['mode']);
        self::assertCount(2, $steps);
    }

    public function testDuplicateKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SearchPlan::parse('[{"key":"php","name":"PHP","query":"PHP","limit":1},{"key":"php","name":"Other","query":"legacy","limit":1}]', 2);
    }

    public function testOversizedStepIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SearchPlan::parse('[{"key":"php","name":"PHP","query":"PHP","limit":11}]', 10);
    }

    public function testKnownCounterpartyNeedsEvidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CounterpartyInput::fromPayload(['value' => 'owner']);
    }

    public function testUnknownAndOmissionRemainDistinct(): void
    {
        self::assertNull(CounterpartyInput::fromPayload(null));
        self::assertNull(CounterpartyInput::fromPayload(['value' => null])?->value);
        self::assertSame('owner', CounterpartyInput::fromPayload(['value' => 'owner', 'reason' => 'Our application', 'confidence' => 0.8, 'verifiedAt' => '2026-09-09T12:00:00Z'])?->value);
    }

    public function testInvalidCounterpartyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CounterpartyInput::fromPayload(['value' => 'direct', 'reason' => 'Guess', 'confidence' => 0.8, 'verifiedAt' => '2026-09-09T12:00:00Z']);
    }
}
