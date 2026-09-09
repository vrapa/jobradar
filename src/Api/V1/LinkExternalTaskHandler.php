<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Action\ActionItemService;
use App\Api\Auth\ApiRequestContext;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class LinkExternalTaskHandler extends BaseHandler
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
            'required' => ['action_item_id', 'provider', 'external_id'],
            'additionalProperties' => false,
            'properties' => [
                'action_item_id' => ['type' => 'integer', 'minimum' => 1],
                'provider' => ['type' => 'string', 'enum' => ['todoist']],
                'external_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'external_url' => ['type' => ['string', 'null'], 'format' => 'uri', 'maxLength' => 2048],
            ],
        ], JSON_THROW_ON_ERROR)))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || !is_int($body['action_item_id'] ?? null) || !is_string($body['provider'] ?? null) || !is_string($body['external_id'] ?? null)) {
            return self::error('Údaje externího úkolu nejsou platné.');
        }
        try {
            $this->actionItems->linkExternalTask(
                $this->requestContext->identity()->ownerUserId,
                $body['action_item_id'],
                $body['provider'],
                $body['external_id'],
                isset($body['external_url']) && is_string($body['external_url']) ? $body['external_url'] : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return self::error($exception->getMessage());
        }
        return new JsonApiResponse(200, ['data' => ['action_item_id' => $body['action_item_id'], 'linked' => true]]);
    }

    private static function error(string $message): JsonApiResponse
    {
        return new JsonApiResponse(422, ['error' => ['code' => 'invalid_external_task', 'message' => $message]]);
    }
}
