<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Search\SearchRequestService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class CreateSearchRequestHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly SearchRequestService $requests,
    ) {
        parent::__construct();
    }

    public function summary(): string
    {
        return 'Vytvoří výslovný ruční požadavek na kontrolu vybraných zdrojů.';
    }

    public function tags(): array
    {
        return ['search'];
    }

    public function params(): array
    {
        return [(new JsonInputParam('body', json_encode([
            'type' => 'object',
            'required' => ['source_ids', 'idempotency_key'],
            'additionalProperties' => false,
            'properties' => [
                'source_ids' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'integer', 'minimum' => 1]],
                'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 200],
            ],
        ], JSON_THROW_ON_ERROR)))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || !isset($body['source_ids'], $body['idempotency_key']) || !is_array($body['source_ids'])) {
            return self::error(422, 'invalid_request', 'Požadavek nemá platný datový tvar.');
        }
        try {
            $sourceIds = array_values(array_map(static function (mixed $id): int {
                if (!is_int($id)) {
                    throw new \InvalidArgumentException('ID zdroje musí být celé číslo.');
                }
                return $id;
            }, $body['source_ids']));
            if (!is_string($body['idempotency_key'])) {
                throw new \InvalidArgumentException('Idempotency klíč musí být řetězec.');
            }
            $result = $this->requests->request(
                $this->requestContext->identity()->ownerUserId,
                $sourceIds,
                $body['idempotency_key'],
            );
        } catch (\InvalidArgumentException $exception) {
            return self::error(422, 'invalid_request', $exception->getMessage());
        }
        return new JsonApiResponse($result->created ? 201 : 200, [
            'data' => [
                'id' => $result->requestId,
                'status' => $result->status,
                'created' => $result->created,
            ],
        ]);
    }

    private static function error(int $status, string $code, string $message): JsonApiResponse
    {
        return new JsonApiResponse($status, ['error' => ['code' => $code, 'message' => $message]]);
    }
}
