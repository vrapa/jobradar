<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Opportunity\OpportunityQueryService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

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

    public function params(): array
    {
        return [new GetInputParam('opportunity_type', InputType::STRING)];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $type = $params['opportunity_type'] ?? null;
        if ($type !== null && !is_string($type)) {
            return new JsonApiResponse(422, ['error' => ['code' => 'invalid_opportunity_type', 'message' => 'Typ příležitosti není platný.']]);
        }
        try {
            $opportunities = $this->opportunities->listCurrent($this->requestContext->identity()->ownerUserId, $type);
        } catch (\InvalidArgumentException $exception) {
            return new JsonApiResponse(422, ['error' => ['code' => 'invalid_opportunity_type', 'message' => $exception->getMessage()]]);
        }
        $items = array_map(
            $this->transformer->transformSummary(...),
            $opportunities,
        );
        return new JsonApiResponse(200, [
            'data' => $items,
            'meta' => ['count' => count($items)],
        ]);
    }
}
