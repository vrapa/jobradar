<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\V1\SourcesHandler;
use App\Bootstrap;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Response\JsonApiResponse;

final class SourcesApiHandlerTest extends TestCase
{
    public function testHandlerReturnsContractShapeWithoutManualSource(): void
    {
        if (getenv('DB_HOST') === false) {
            self::markTestSkipped('Integrační databáze není nakonfigurovaná.');
        }
        $container = (new Bootstrap(dirname(__DIR__, 2)))->bootConsole();
        $handler = $container->getByType(SourcesHandler::class);
        $response = $handler->handle([]);

        self::assertInstanceOf(JsonApiResponse::class, $response);
        self::assertSame(200, $response->getCode());
        $payload = $response->getPayload();
        self::assertIsArray($payload);
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('meta', $payload);
        self::assertSame(count($payload['data']), $payload['meta']['count']);
        foreach ($payload['data'] as $source) {
            self::assertNotSame('manual', $source['type']);
            self::assertArrayHasKey('access', $source);
        }
    }
}
