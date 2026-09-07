<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Opportunity\OpportunityImport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpportunityImportTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string, ?string}> */
    public static function tooLongValues(): iterable
    {
        yield 'original title' => [str_repeat('a', 501), 'PHP vývojář', null, null];
        yield 'translated title' => ['PHP Developer', str_repeat('a', 501), null, null];
        yield 'company' => ['PHP Developer', 'PHP vývojář', str_repeat('a', 256), null];
        yield 'language' => ['PHP Developer', 'PHP vývojář', null, str_repeat('a', 17)];
    }

    #[DataProvider('tooLongValues')]
    public function testRejectsValuesLongerThanStorageLimits(
        string $originalTitle,
        string $translatedTitle,
        ?string $companyName,
        ?string $sourceLanguage,
    ): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpportunityImport(
            url: 'https://example.test/job',
            originalTitle: $originalTitle,
            originalText: 'Synthetic offer.',
            companyName: $companyName,
            translatedTitle: $translatedTitle,
            sourceLanguage: $sourceLanguage,
        );
    }
}
