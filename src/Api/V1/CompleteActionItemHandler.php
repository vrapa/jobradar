<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Action\ActionItemService;
use App\Api\Auth\ApiRequestContext;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class CompleteActionItemHandler extends BaseHandler
{
    public function __construct(private readonly ApiRequestContext $requestContext, private readonly ActionItemService $actionItems)
    {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['action-items'];
    }

    public function params(): array
    {
        return [(new JsonInputParam('body', json_encode([
            'type' => 'object',
            'required' => ['action_item_id'],
            'additionalProperties' => false,
            'properties' => ['action_item_id' => ['type' => 'integer', 'minimum' => 1]],
        ], JSON_THROW_ON_ERROR)))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || !is_int($body['action_item_id'] ?? null)) {
            return self::error('ID navazujícího úkolu není platné.');
        }
        try {
            $this->actionItems->complete($this->requestContext->identity()->ownerUserId, $body['action_item_id'], 'assistant');
        } catch (\InvalidArgumentException $exception) {
            return self::error($exception->getMessage());
        }
        return new JsonApiResponse(200, ['data' => [
            'action_item_id' => $body['action_item_id'],
            'status' => 'completed',
            'decision_changed' => false,
            'application_submitted' => false,
        ]]);
    }

    private static function error(string $message): JsonApiResponse
    {
        return new JsonApiResponse(422, ['error' => ['code' => 'invalid_action_item', 'message' => $message]]);
    }
}
