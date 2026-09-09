<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Action\ActionItemService;
use App\Api\Auth\ApiRequestContext;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class ListActionItemsHandler extends BaseHandler
{
    public function __construct(private readonly ApiRequestContext $requestContext, private readonly ActionItemService $actionItems)
    {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['action-items'];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $items = $this->actionItems->listOpen($this->requestContext->identity()->ownerUserId, true);
        return new JsonApiResponse(200, ['data' => $items, 'meta' => ['count' => count($items)]]);
    }
}
