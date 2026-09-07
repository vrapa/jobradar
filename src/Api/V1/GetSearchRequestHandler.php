<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Search\SourceQueryService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class GetSearchRequestHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly SourceQueryService $queries,
        private readonly SearchRequestTransformer $transformer,
    ) {
        parent::__construct();
    }

    public function summary(): string
    {
        return 'Vrátí stav a doložené pokrytí jedné vlastní kontroly.';
    }

    public function tags(): array
    {
        return ['search'];
    }

    public function params(): array
    {
        return [(new GetInputParam('id', InputType::INTEGER))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $id = $params['id'] ?? null;
        if (!is_int($id) || $id < 1) {
            return new JsonApiResponse(422, ['error' => ['code' => 'invalid_request_id', 'message' => 'ID kontroly není platné.']]);
        }
        $request = $this->queries->getRequestDetail($this->requestContext->identity()->ownerUserId, $id);
        if ($request === null) {
            return new JsonApiResponse(404, ['error' => ['code' => 'search_request_not_found', 'message' => 'Kontrola nebyla nalezena.']]);
        }
        return new JsonApiResponse(200, ['data' => $this->transformer->transform($request)]);
    }
}
