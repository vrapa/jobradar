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
    public function testAccessPreparationIsOptionalAndForwardedToApi(): void
    {
        $http = new McpRecordingHttpClient([new RunnerHttpResponse(200, ['data' => []]), new RunnerHttpResponse(200, ['data' => []])]);
        $tools = new JobRadarMcpTools(new JobRadarMcpApiClient('https://jobradar.example.test/api/v1', 'synthetic-token', $http));
        $tools->requestSearch([3], 'legacy-request-key');
        $tools->requestSearch([3], 'prepare-request-key', true);
        self::assertFalse($http->bodies[0]['prepare_access'] ?? null);
        self::assertTrue($http->bodies[1]['prepare_access'] ?? null);
        self::assertSame('POST /api/v1/search-requests', $http->requests[1]);
    }

    public function testReactionQueueAndDelegatedDecisionsUseVersionedApi(): void
    {
        $http = new McpRecordingHttpClient([
            new RunnerHttpResponse(200, ['data' => [
                ['id' => 1, 'decision' => 'undecided'],
                ['id' => 2, 'decision' => 'react'],
                ['id' => 3, 'decision' => 'uninteresting'],
                ['id' => 4, 'decision' => 'react', 'workflow_status' => 'awaiting_response'],
                ['id' => 5, 'decision' => 'react', 'workflow_status' => 'closed'],
            ], 'meta' => ['count' => 3]]),
            new RunnerHttpResponse(200, ['data' => [
                'id' => 7,
                'status' => 'active',
                'application_submitted' => false,
            ]]),
            new RunnerHttpResponse(200, ['data' => [
                'delegation_id' => 7,
                'processed' => 2,
                'changed' => 2,
                'application_submitted' => false,
            ]]),
            new RunnerHttpResponse(200, ['data' => [
                'opportunity_id' => 2,
                'delegation_id' => 8,
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

        $created = $tools->createDecisionDelegation([
            'opportunity_ids' => [2, 3],
            'candidate_profile_id' => 4,
            'scoring_rule_set_id' => 5,
            'expires_at' => '2030-01-01T12:00:00+00:00',
            'idempotency_key' => 'synthetic-create-delegation',
        ]);
        self::assertIsArray($created->structuredContent);
        self::assertSame(7, $created->structuredContent['data']['id']);

        $batch = $tools->setDecisionsBatch(7, [
            'decisions' => [
                ['opportunity_id' => 2, 'expected_lock_version' => 0, 'decision' => 'react'],
                ['opportunity_id' => 3, 'expected_lock_version' => 1, 'decision' => 'uninteresting', 'reason' => 'low_rate'],
            ],
            'idempotency_key' => 'synthetic-decision-batch',
        ]);
        self::assertIsArray($batch->structuredContent);
        self::assertSame(2, $batch->structuredContent['data']['processed']);

        $decision = $tools->setDecision(2, [
            'delegation_id' => 8,
            'expected_lock_version' => 0,
            'decision' => 'react',
            'idempotency_key' => 'synthetic-decision-key',
        ]);
        self::assertIsArray($decision->structuredContent);
        self::assertFalse($decision->structuredContent['data']['application_submitted']);
        self::assertSame([
            'GET /api/v1/opportunities',
            'POST /api/v1/decision-delegations',
            'POST /api/v1/decision-delegations/7/decisions',
            'PUT /api/v1/opportunities/2/decision',
        ], $http->requests);
        self::assertSame('Authorization: Bearer jr_mcp_secret', $http->headers[0][0]);
        self::assertIsArray($http->bodies[2]);
        self::assertSame(2, count($http->bodies[2]['decisions']));
        self::assertIsArray($http->bodies[3]);
        self::assertSame(8, $http->bodies[3]['delegation_id']);
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
