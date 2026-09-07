<?php

declare(strict_types=1);

namespace App\Runner;

use App\Search\SourceRunResult;
use App\Search\SourceRunScope;

final class JobRadarRunnerApiClient implements RunnerApiClientInterface
{
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $token,
        private readonly RunnerHttpClientInterface $http,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('URL API runneru musí být platné HTTP(S) URL bez přihlašovacích údajů.');
        }
    }

    public function listSources(): array
    {
        $payload = $this->success($this->request('GET', '/sources'));
        $data = $payload['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('API zdrojů vrátilo neočekávaný datový tvar.');
        }
        return array_map(function (mixed $item): RunnerSource {
            if (!is_array($item) || !isset($item['id'], $item['name'], $item['url'], $item['type'], $item['access'])
                || !is_int($item['id']) || !is_string($item['name']) || !is_string($item['url']) || !is_string($item['type'])
                || !is_array($item['access']) || !isset($item['access']['status'], $item['access']['intervention_required'])
                || !is_string($item['access']['status']) || !is_bool($item['access']['intervention_required'])
            ) {
                throw new \RuntimeException('API zdrojů vrátilo neplatný zdroj.');
            }
            return new RunnerSource(
                $item['id'],
                $item['name'],
                $item['url'],
                $item['type'],
                $item['access']['status'],
                $item['access']['intervention_required'],
            );
        }, $data);
    }

    public function claimLease(): ?RunnerApiLease
    {
        $payload = $this->success($this->request('POST', '/runner/lease', []));
        $data = $payload['data'] ?? null;
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || !isset($data['request_id'], $data['run_id'], $data['lease_token'], $data['lease_expires_at'], $data['source_ids'])
            || !is_int($data['request_id']) || !is_int($data['run_id']) || !is_string($data['lease_token'])
            || !is_string($data['lease_expires_at']) || !is_array($data['source_ids'])
            || array_any($data['source_ids'], static fn (mixed $id): bool => !is_int($id))
        ) {
            throw new \RuntimeException('API lease vrátilo neočekávaný datový tvar.');
        }
        try {
            $expiresAt = new \DateTimeImmutable($data['lease_expires_at']);
        } catch (\Exception $exception) {
            throw new \RuntimeException('API lease vrátilo neplatnou expiraci.', previous: $exception);
        }
        /** @var list<int> $sourceIds */
        $sourceIds = array_values($data['source_ids']);
        return new RunnerApiLease($data['request_id'], $data['run_id'], $data['lease_token'], $expiresAt, $sourceIds);
    }

    public function startSource(int $runId, int $sourceId, string $leaseToken, SourceRunScope $scope): void
    {
        $this->success($this->request('POST', sprintf('/search-runs/%d/sources/%d/progress', $runId, $sourceId), [
            'event' => 'start',
            'lease_token' => $leaseToken,
            'scope' => [
                'description' => $scope->description,
                'filters' => $scope->filters,
                'horizon_from' => $scope->horizonFrom?->format(DATE_ATOM),
                'horizon_to' => $scope->horizonTo?->format(DATE_ATOM),
            ],
        ]));
    }

    public function finishSource(int $runId, int $sourceId, string $leaseToken, SourceRunResult $result): void
    {
        $this->success($this->request('POST', sprintf('/search-runs/%d/sources/%d/progress', $runId, $sourceId), [
            'event' => 'finish',
            'lease_token' => $leaseToken,
            'result' => [
                'status' => $result->status,
                'pages_traversed' => $result->pagesTraversed,
                'displayed_count' => $result->displayedCount,
                'detail_opened_count' => $result->detailOpenedCount,
                'stored_count' => $result->storedCount,
                'updated_count' => $result->updatedCount,
                'duplicate_count' => $result->duplicateCount,
                'rejected_count' => $result->rejectedCount,
                'incomplete_reason' => $result->incompleteReason,
                'error_code' => $result->errorCode,
            ],
        ]));
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): RunnerHttpResponse
    {
        if (trim($this->token) === '') {
            throw new \RuntimeException('Runner token není nastavený v prostředí.');
        }
        return $this->http->request($method, $this->baseUrl . $path, ['Authorization: Bearer ' . $this->token], $body);
    }

    /** @return array<string, mixed> */
    private function success(RunnerHttpResponse $response): array
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            $error = $response->payload['error'] ?? null;
            $code = is_array($error) && isset($error['code']) && is_string($error['code']) ? $error['code'] : 'api_error';
            throw new \RuntimeException(sprintf('API JobRadaru odmítlo operaci (%s, HTTP %d).', $code, $response->statusCode));
        }
        return $response->payload;
    }
}
