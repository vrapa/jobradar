<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Search\SearchRequestControlService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class ResumeSearchRequestHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly SearchRequestControlService $controls,
    ) {
        parent::__construct();
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
        return $this->change($params, fn (int $userId, int $id): string => $this->controls->requestResume($userId, $id));
    }

    /** @param array<string, mixed> $params @param callable(int, int): string $operation */
    private function change(array $params, callable $operation): ResponseInterface
    {
        $id = $params['id'] ?? null;
        if (!is_int($id) || $id < 1) {
            return new JsonApiResponse(422, ['error' => ['code' => 'invalid_request_id', 'message' => 'ID kontroly není platné.']]);
        }
        try {
            $status = $operation($this->requestContext->identity()->ownerUserId, $id);
        } catch (\InvalidArgumentException $exception) {
            return new JsonApiResponse(409, ['error' => ['code' => 'invalid_search_transition', 'message' => $exception->getMessage()]]);
        }
        return new JsonApiResponse(200, ['data' => ['id' => $id, 'status' => $status]]);
    }
}
