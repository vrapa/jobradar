<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Search\RunnerDeviceService;
use App\Search\RunnerLeaseService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class ClaimRunnerLeaseHandler extends BaseHandler
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

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        try {
            $deviceId = $this->devices->requireDeviceId($this->requestContext->identity());
            $lease = $this->leases->claimNext($deviceId);
        } catch (\InvalidArgumentException $exception) {
            return new JsonApiResponse(403, ['error' => ['code' => 'runner_not_available', 'message' => $exception->getMessage()]]);
        }
        if ($lease === null) {
            return new JsonApiResponse(200, ['data' => null]);
        }
        return new JsonApiResponse(200, ['data' => [
            'request_id' => $lease->requestId,
            'run_id' => $lease->runId,
            'lease_token' => $lease->token,
            'lease_expires_at' => $lease->expiresAt->format(DATE_ATOM),
            'source_ids' => $lease->sourceIds,
        ]]);
    }
}
