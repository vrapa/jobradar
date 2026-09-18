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
        return $this->call(fn (): array => $this->api->get('/sources'));
    }

    public function listSourcesRequiringLogin(): CallToolResult
    {
        return $this->call(fn (): array => $this->api->get('/sources/requiring-login'));
    }

    /** @param list<int> $sourceIds */
    public function requestSearch(array $sourceIds, string $idempotencyKey, bool $prepareAccess = false): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/search-requests', [
            'source_ids' => $sourceIds,
            'idempotency_key' => $idempotencyKey,
            'prepare_access' => $prepareAccess,
        ]));
    }

    public function getSearchStatus(int $requestId): CallToolResult
    {
        return $this->call(fn (): array => $this->api->get('/search-requests/' . $requestId));
    }

    public function resumeSearch(int $requestId): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/search-requests/' . $requestId . '/resume'));
    }

    public function cancelSearch(int $requestId): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/search-requests/' . $requestId . '/cancel'));
    }

    public function listOpportunities(): CallToolResult
    {
        return $this->call(fn (): array => $this->api->get('/opportunities'));
    }

    public function listReactionQueue(): CallToolResult
    {
        return $this->call(function (): array {
            $payload = $this->api->get('/opportunities');
            $data = $payload['data'] ?? null;
            if (!is_array($data) || !array_is_list($data)) {
                throw new \RuntimeException('API nabídek vrátilo neočekávaný datový tvar.');
            }
            $items = array_values(array_filter(
                $data,
                static fn (mixed $item): bool => is_array($item) && ($item['decision'] ?? null) === 'react'
                    && in_array($item['workflow_status'] ?? 'none', ['none', 'preparing', 'awaiting_approval'], true),
            ));
            return ['data' => $items, 'meta' => ['count' => count($items)]];
        });
    }

    public function getOpportunity(int $opportunityId): CallToolResult
    {
        return $this->call(fn (): array => $this->api->get('/opportunities/' . $opportunityId));
    }

    /** @param array<string, mixed> $opportunity */
    public function importOpportunityVersion(array $opportunity): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/opportunities/import', $opportunity));
    }

    /** @param array<string, mixed> $assessment */
    public function saveAssessment(int $opportunityId, array $assessment): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post(
            sprintf('/opportunities/%d/assessments', $opportunityId),
            $assessment,
        ));
    }

    /** @param array<string, mixed> $decision */
    public function setDecision(int $opportunityId, array $decision): CallToolResult
    {
        return $this->call(fn (): array => $this->api->put(
            sprintf('/opportunities/%d/decision', $opportunityId),
            $decision,
        ));
    }

    /** @param array<string, mixed> $delegation */
    public function createDecisionDelegation(array $delegation): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/decision-delegations', $delegation));
    }

    /** @param array<string, mixed> $batch */
    public function setDecisionsBatch(int $delegationId, array $batch): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post(
            sprintf('/decision-delegations/%d/decisions', $delegationId),
            $batch,
        ));
    }

    public function listPendingActionItems(): CallToolResult
    {
        return $this->call(fn (): array => $this->api->get('/action-items'));
    }

    public function linkTodoistTask(int $actionItemId, string $externalId, ?string $externalUrl = null): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/action-items/external-task', [
            'action_item_id' => $actionItemId,
            'provider' => 'todoist',
            'external_id' => $externalId,
            'external_url' => $externalUrl,
        ]));
    }

    public function completeActionItem(int $actionItemId): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/action-items/complete', ['action_item_id' => $actionItemId]));
    }

    /** @param array<string,mixed> $event */
    public function recordApplicationEvent(int $opportunityId, array $event): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/applications/events', [...$event, 'opportunity_id' => $opportunityId]));
    }

    public function listLinkedActionItems(): CallToolResult
    {
        return $this->call(fn (): array => $this->api->get('/action-items/linked'));
    }

    public function acknowledgeTodoistStatus(int $actionItemId, string $status): CallToolResult
    {
        return $this->call(fn (): array => $this->api->post('/action-items/synced', ['action_item_id' => $actionItemId, 'status' => $status]));
    }

    /** @param callable(): array<string, mixed> $operation */
    private function call(callable $operation): CallToolResult
    {
        try {
            return self::result($operation());
        } catch (JobRadarMcpApiException $exception) {
            $payload = ['error' => [
                'code' => $exception->apiCode,
                'http_status' => $exception->httpStatus,
                'message' => $exception->getMessage(),
            ]];
            return new CallToolResult(
                [new TextContent(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))],
                isError: true,
                structuredContent: $payload,
            );
        } catch (\RuntimeException) {
            $payload = ['error' => [
                'code' => 'jobradar_unavailable',
                'message' => 'JobRadar API není pro MCP dostupné.',
            ]];
            return new CallToolResult(
                [new TextContent(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))],
                isError: true,
                structuredContent: $payload,
            );
        }
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
