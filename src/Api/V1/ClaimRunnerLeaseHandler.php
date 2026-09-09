<?php

declare(strict_types=1);

namespace App\Api\V1;

use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

/** Retired: demo workers must never acquire production requests. */
final class ClaimRunnerLeaseHandler extends BaseHandler
{
    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        return new JsonApiResponse(410, ['error' => [
            'code' => 'legacy_worker_disabled',
            'message' => 'Použijte oddělené vykonávací MCP a /api/v1/runner/execution.',
        ]]);
    }
}
