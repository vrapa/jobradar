<?php

declare(strict_types=1);

namespace App\Runner;

final class CurlRunnerHttpClient implements RunnerHttpClientInterface
{
    public function request(string $method, string $url, array $headers, ?array $body = null): RunnerHttpResponse
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
            throw new \InvalidArgumentException('Runner HTTP klient dostal nepodporovanou metodu.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('HTTP klient runneru se nepodařilo inicializovat.');
        }
        $headers[] = 'Accept: application/json';
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            curl_close($handle);
            throw new \RuntimeException('API JobRadaru není pro runner dostupné.');
        }
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('API JobRadaru vrátilo neplatný JSON.', previous: $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException('API JobRadaru vrátilo neočekávaný datový tvar.');
        }
        return new RunnerHttpResponse($statusCode, $payload);
    }
}
