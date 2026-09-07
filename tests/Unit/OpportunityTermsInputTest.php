<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Opportunity\OpportunityTermsInput;
use PHPUnit\Framework\TestCase;

final class OpportunityTermsInputTest extends TestCase
{
    public function testAcceptsUnknownValuesAsNull(): void
    {
        $terms = new OpportunityTermsInput();

        self::assertNull($terms->rateMin);
        self::assertNull($terms->rateConfidence);
        self::assertNull($terms->workFromCzechia);
    }

    public function testRejectsInvalidRangeAndConfidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpportunityTermsInput(rateMin: '900', rateMax: '800', rateConfidence: '1.2');
    }

    public function testRejectsNonIsoCurrencyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpportunityTermsInput(currency: 'Kč');
    }
}
