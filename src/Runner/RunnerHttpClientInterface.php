<?php

declare(strict_types=1);

namespace App\Runner;

interface RunnerHttpClientInterface
{
    /**
     * @param list<string> $headers
     * @param array<string, mixed>|null $body
     */
    public function request(string $method, string $url, array $headers, ?array $body = null): RunnerHttpResponse;
}
