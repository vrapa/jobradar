<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Opportunity\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    #[DataProvider('normalizedUrls')]
    public function testNormalizesCanonicalUrl(string $input, string $expected): void
    {
        self::assertSame($expected, (new UrlNormalizer())->normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizedUrls(): iterable
    {
        yield 'host and tracking' => [
            'HTTPS://Jobs.Example.COM/roles/php/?utm_source=mail&b=2&a=1#apply',
            'https://jobs.example.com/roles/php?a=1&b=2',
        ];
        yield 'default port and slash' => [
            'https://example.com:443//jobs///42/',
            'https://example.com/jobs/42',
        ];
    }

    public function testRejectsNonHttpUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlNormalizer())->normalize('javascript:alert(1)');
    }

    public function testRejectsCredentialsInUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UrlNormalizer())->normalize('https://user:secret@example.test/job');
    }
}
