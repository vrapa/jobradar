<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Search\RunnerDeviceService;
use App\Search\RunnerLeaseService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class RenewRunnerLeaseHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly RunnerDeviceService $devices,
        private readonly RunnerLeaseService $leases,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['runner'];
    }

    public function params(): array
    {
        return [(new JsonInputParam('body', json_encode([
            'type' => 'object',
            'required' => ['request_id', 'lease_token'],
            'additionalProperties' => false,
            'properties' => [
                'request_id' => ['type' => 'integer', 'minimum' => 1],
                'lease_token' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ], JSON_THROW_ON_ERROR)))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || !isset($body['request_id'], $body['lease_token'])
            || !is_int($body['request_id']) || !is_string($body['lease_token'])
        ) {
            return new JsonApiResponse(422, ['error' => ['code' => 'invalid_lease', 'message' => 'Údaje lease nejsou platné.']]);
        }
        try {
            $deviceId = $this->devices->requireDeviceId($this->requestContext->identity());
            $expiresAt = $this->leases->renew($deviceId, $body['request_id'], $body['lease_token']);
        } catch (\InvalidArgumentException $exception) {
            return new JsonApiResponse(409, ['error' => ['code' => 'lease_not_active', 'message' => $exception->getMessage()]]);
        }
        return new JsonApiResponse(200, ['data' => [
            'request_id' => $body['request_id'],
            'lease_expires_at' => $expiresAt->format(DATE_ATOM),
        ]]);
    }
}
