<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

final class JobRadarMcpTools
{
    public function __construct(private readonly JobRadarMcpApiClient $api)
    {
    }

    public function listSources(): CallToolResult
    {
        return self::result($this->api->get('/sources'));
    }

    public function listSourcesRequiringLogin(): CallToolResult
    {
        return self::result($this->api->get('/sources/requiring-login'));
    }

    /** @param list<int> $sourceIds */
    public function requestSearch(array $sourceIds, string $idempotencyKey): CallToolResult
    {
        return self::result($this->api->post('/search-requests', [
            'source_ids' => $sourceIds,
            'idempotency_key' => $idempotencyKey,
        ]));
    }

    public function getSearchStatus(int $requestId): CallToolResult
    {
        return self::result($this->api->get('/search-requests/' . $requestId));
    }

    public function resumeSearch(int $requestId): CallToolResult
    {
        return self::result($this->api->post('/search-requests/' . $requestId . '/resume'));
    }

    public function cancelSearch(int $requestId): CallToolResult
    {
        return self::result($this->api->post('/search-requests/' . $requestId . '/cancel'));
    }

    public function listOpportunities(): CallToolResult
    {
        return self::result($this->api->get('/opportunities'));
    }

    public function listReactionQueue(): CallToolResult
    {
        $payload = $this->api->get('/opportunities');
        $data = $payload['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('API nabídek vrátilo neočekávaný datový tvar.');
        }
        $items = array_values(array_filter(
            $data,
            static fn (mixed $item): bool => is_array($item) && ($item['decision'] ?? null) === 'react',
        ));
        return self::result(['data' => $items, 'meta' => ['count' => count($items)]]);
    }

    public function getOpportunity(int $opportunityId): CallToolResult
    {
        return self::result($this->api->get('/opportunities/' . $opportunityId));
    }

    /** @param array<string, mixed> $opportunity */
    public function importOpportunityVersion(array $opportunity): CallToolResult
    {
        return self::result($this->api->post('/opportunities/import', $opportunity));
    }

    /** @param array<string, mixed> $assessment */
    public function saveAssessment(int $opportunityId, array $assessment): CallToolResult
    {
        return self::result($this->api->post(
            sprintf('/opportunities/%d/assessments', $opportunityId),
            $assessment,
        ));
    }

    /** @param array<string, mixed> $decision */
    public function setDecision(int $opportunityId, array $decision): CallToolResult
    {
        return self::result($this->api->put(
            sprintf('/opportunities/%d/decision', $opportunityId),
            $decision,
        ));
    }

    /** @param array<string, mixed> $payload */
    private static function result(array $payload): CallToolResult
    {
        return new CallToolResult(
            [new TextContent(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))],
            structuredContent: $payload,
        );
    }
}
