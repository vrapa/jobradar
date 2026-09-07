<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Search\SourceRunResult;
use PHPUnit\Framework\TestCase;

final class SourceRunResultTest extends TestCase
{
    public function testCompleteRequiresAllActualCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SourceRunResult('complete', pagesTraversed: 1, displayedCount: 0);
    }

    public function testActualZeroCountsAreAllowedAfterTraversal(): void
    {
        $result = new SourceRunResult('complete', 1, 0, 0, 0, 0, 0, 0);
        self::assertSame(0, $result->storedCount);
    }

    public function testPartialRequiresReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SourceRunResult('partial', pagesTraversed: 1);
    }
}
