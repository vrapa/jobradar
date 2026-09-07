<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Opportunity\OpportunityJsonMapper;
use App\Opportunity\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class OpportunityJsonMapperTest extends TestCase
{
    public function testMapsObjectAndPreservesUnknownAsNull(): void
    {
        $imports = $this->mapper()->map(json_encode([
            'url' => 'https://example.test/job',
            'originalTitle' => 'PHP Developer',
            'originalText' => '<script>untrusted()</script>',
            'incomplete' => true,
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, $imports);
        self::assertNull($imports[0]->companyName);
        self::assertSame('<script>untrusted()</script>', $imports[0]->originalText);
        self::assertTrue($imports[0]->incomplete);
    }

    public function testMapsList(): void
    {
        $json = '[{"url":"https://example.test/1","originalTitle":"One","originalText":"Text"},'
            . '{"url":"https://example.test/2","originalTitle":"Two","originalText":"Text"}]';

        self::assertCount(2, $this->mapper()->map($json));
    }

    public function testRejectsUnknownFieldInsteadOfSilentlyLosingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper()->map(
            '{"url":"https://example.test","originalTitle":"Title","originalText":"Text","titel":"typo"}',
        );
    }

    public function testRejectsNonBooleanIncompleteFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper()->map(
            '{"url":"https://example.test","originalTitle":"Title","originalText":"Text","incomplete":0}',
        );
    }

    private function mapper(): OpportunityJsonMapper
    {
        return new OpportunityJsonMapper(new UrlNormalizer());
    }
}
