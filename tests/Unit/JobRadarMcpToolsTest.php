<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mcp\JobRadarMcpApiClient;
use App\Mcp\JobRadarMcpTools;
use App\Runner\RunnerHttpClientInterface;
use App\Runner\RunnerHttpResponse;
use PHPUnit\Framework\TestCase;

final class JobRadarMcpToolsTest extends TestCase
{
    public function testReactionQueueIsReadOnlyProjectionAndDecisionUsesVersionedApi(): void
    {
        $http = new McpRecordingHttpClient([
            new RunnerHttpResponse(200, ['data' => [
                ['id' => 1, 'decision' => 'undecided'],
                ['id' => 2, 'decision' => 'react'],
                ['id' => 3, 'decision' => 'uninteresting'],
            ], 'meta' => ['count' => 3]]),
            new RunnerHttpResponse(200, ['data' => [
                'opportunity_id' => 2,
                'decision' => 'react',
                'lock_version' => 1,
                'application_submitted' => false,
            ]]),
        ]);
        $tools = new JobRadarMcpTools(new JobRadarMcpApiClient(
            'https://jobradar.example.test/api/v1',
            'jr_mcp_secret',
            $http,
        ));

        $queue = $tools->listReactionQueue();
        self::assertIsArray($queue->structuredContent);
        self::assertSame(1, $queue->structuredContent['meta']['count']);
        self::assertSame(2, $queue->structuredContent['data'][0]['id']);

        $decision = $tools->setDecision(2, [
            'expected_lock_version' => 0,
            'decision' => 'react',
            'idempotency_key' => 'synthetic-decision-key',
        ]);
        self::assertIsArray($decision->structuredContent);
        self::assertFalse($decision->structuredContent['data']['application_submitted']);
        self::assertSame(['GET /api/v1/opportunities', 'PUT /api/v1/opportunities/2/decision'], $http->requests);
        self::assertSame('Authorization: Bearer jr_mcp_secret', $http->headers[0][0]);
        self::assertIsArray($http->bodies[1]);
        self::assertSame('react', $http->bodies[1]['decision']);
    }

    public function testApiErrorDoesNotExposeRemoteTextOrToken(): void
    {
        $http = new McpRecordingHttpClient([
            new RunnerHttpResponse(403, ['error' => ['code' => 'scope_denied', 'message' => 'remote sensitive detail']]),
        ]);
        $tools = new JobRadarMcpTools(new JobRadarMcpApiClient(
            'https://jobradar.example.test/api/v1',
            'jr_hidden_token',
            $http,
        ));

        try {
            $tools->listSources();
            self::fail('Zakázaná operace musí selhat.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('scope_denied', $exception->getMessage());
            self::assertStringNotContainsString('remote sensitive detail', $exception->getMessage());
            self::assertStringNotContainsString('jr_hidden_token', $exception->getMessage());
        }
    }
}

final class McpRecordingHttpClient implements RunnerHttpClientInterface
{
    /** @var list<string> */
    public array $requests = [];

    /** @var list<list<string>> */
    public array $headers = [];

    /** @var list<array<string, mixed>|null> */
    public array $bodies = [];

    /** @param list<RunnerHttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $headers, ?array $body = null): RunnerHttpResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->requests[] = $method . ' ' . (is_string($path) ? $path : '');
        $this->headers[] = $headers;
        $this->bodies[] = $body;
        $response = array_shift($this->responses);
        if (!$response instanceof RunnerHttpResponse) {
            throw new \LogicException('Testovací MCP HTTP fronta je prázdná.');
        }
        return $response;
    }
}
