<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Runner\RunnerHttpClientInterface;
use App\Runner\RunnerHttpResponse;

final class JobRadarMcpApiClient
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
            throw new \InvalidArgumentException('URL API MCP klienta musí být platné HTTP(S) URL bez přihlašovacích údajů.');
        }
        if (trim($this->token) === '') {
            throw new \InvalidArgumentException('MCP API token není nastavený v prostředí.');
        }
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->success($this->request('GET', $path));
    }

    /** @param array<string, mixed> $body
     *  @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->success($this->request('POST', $path, $body));
    }

    /** @param array<string, mixed> $body
     *  @return array<string, mixed>
     */
    public function put(string $path, array $body): array
    {
        return $this->success($this->request('PUT', $path, $body));
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): RunnerHttpResponse
    {
        if (!str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new \InvalidArgumentException('MCP klient dostal neplatnou cestu API.');
        }
        return $this->http->request(
            $method,
            $this->baseUrl . $path,
            ['Authorization: Bearer ' . $this->token],
            $body,
        );
    }

    /** @return array<string, mixed> */
    private function success(RunnerHttpResponse $response): array
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            $error = $response->payload['error'] ?? null;
            $code = is_array($error) && isset($error['code']) && is_string($error['code']) ? $error['code'] : 'api_error';
            throw new \RuntimeException(sprintf('API JobRadaru odmítlo MCP operaci (%s, HTTP %d).', $code, $response->statusCode));
        }
        return $response->payload;
    }
}
