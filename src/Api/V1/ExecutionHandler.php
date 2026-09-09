<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Search\ExecutionService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class ExecutionHandler extends BaseHandler
{
    public function __construct(private readonly ApiRequestContext $context, private readonly ExecutionService $execution)
    {
        parent::__construct();
    }

    public function params(): array
    {
        return [(new JsonInputParam('body', '{"type":"object","required":["operation"],"properties":{"operation":{"type":"string"}}}'))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        try {
            if (!is_array($params['body'] ?? null)) {
                throw new \InvalidArgumentException('Vyžadován JSON objekt.');
            }
            return new JsonApiResponse(200, ['data' => $this->execution->execute($this->context->identity(), $params['body'])]);
        } catch (\InvalidArgumentException|\JsonException|\ValueError|\App\Opportunity\OpportunityConflictException $exception) {
            return new JsonApiResponse(409, ['error' => ['code' => 'execution_rejected', 'message' => $exception->getMessage()]]);
        }
    }
}
