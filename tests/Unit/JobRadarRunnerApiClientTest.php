<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Runner\JobRadarRunnerApiClient;
use App\Runner\RunnerHttpClientInterface;
use App\Runner\RunnerHttpResponse;
use App\Search\SourceRunResult;
use App\Search\SourceRunScope;
use PHPUnit\Framework\TestCase;

final class JobRadarRunnerApiClientTest extends TestCase
{
    public function testMapsLeaseSourcesAndProgressWithoutExposingDatabase(): void
    {
        $http = new QueuedRunnerHttpClient([
            new RunnerHttpResponse(200, ['data' => [[
                'id' => 4,
                'name' => 'Synthetic source',
                'url' => 'https://source.example.test',
                'type' => 'fake',
                'access' => ['status' => 'unknown', 'intervention_required' => false],
            ]]]),
            new RunnerHttpResponse(200, ['data' => [
                'request_id' => 8,
                'run_id' => 9,
                'lease_token' => str_repeat('a', 64),
                'lease_expires_at' => '2030-01-01T00:05:00+00:00',
                'source_ids' => [4],
            ]]),
            new RunnerHttpResponse(200, ['data' => ['event' => 'start']]),
            new RunnerHttpResponse(200, ['data' => ['event' => 'finish']]),
        ]);
        $client = new JobRadarRunnerApiClient('https://jobradar.example.test/api/v1/', 'jr_secret', $http);

        $sources = $client->listSources();
        $lease = $client->claimLease();
        self::assertNotNull($lease);
        $client->startSource($lease->runId, 4, $lease->token, new SourceRunScope('Synthetic scope.', ['page' => 1]));
        $client->finishSource($lease->runId, 4, $lease->token, new SourceRunResult(
            'complete', 1, 0, 0, 0, 0, 0, 0,
        ));

        self::assertSame('Synthetic source', $sources[0]->name);
        self::assertSame(8, $lease->requestId);
        self::assertSame(
            ['GET /api/v1/sources', 'POST /api/v1/runner/lease', 'POST /api/v1/search-runs/9/sources/4/progress', 'POST /api/v1/search-runs/9/sources/4/progress'],
            $http->requests,
        );
        self::assertSame('Authorization: Bearer jr_secret', $http->headers[0][0]);
        self::assertIsArray($http->bodies[2]);
        self::assertIsArray($http->bodies[3]);
        self::assertSame('start', $http->bodies[2]['event']);
        self::assertSame('finish', $http->bodies[3]['event']);
        self::assertSame(0, $http->bodies[3]['result']['stored_count']);
    }

    public function testRejectsApiErrorWithoutRepeatingRemoteMessageOrToken(): void
    {
        $http = new QueuedRunnerHttpClient([
            new RunnerHttpResponse(401, ['error' => ['code' => 'unauthorized', 'message' => 'remote secret detail']]),
        ]);
        $client = new JobRadarRunnerApiClient('https://jobradar.example.test/api/v1', 'jr_super_secret', $http);

        try {
            $client->listSources();
            self::fail('Neautorizovaná odpověď musí skončit bezpečnou výjimkou.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unauthorized', $exception->getMessage());
            self::assertStringNotContainsString('remote secret detail', $exception->getMessage());
            self::assertStringNotContainsString('jr_super_secret', $exception->getMessage());
        }
    }
}

final class QueuedRunnerHttpClient implements RunnerHttpClientInterface
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
            throw new \LogicException('Testovací HTTP fronta je prázdná.');
        }
        return $response;
    }
}
