<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Opportunity\OpportunityQueryService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class ListOpportunitiesHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly OpportunityQueryService $opportunities,
        private readonly OpportunityTransformer $transformer,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['opportunities'];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $items = array_map(
            $this->transformer->transformSummary(...),
            $this->opportunities->listCurrent($this->requestContext->identity()->ownerUserId),
        );
        return new JsonApiResponse(200, [
            'data' => $items,
            'meta' => ['count' => count($items)],
        ]);
    }
}
