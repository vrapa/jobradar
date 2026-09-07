<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\AssistantActionService;
use App\Api\Auth\ApiRequestContext;
use App\Decision\DecisionDelegationService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class CreateDecisionDelegationHandler extends BaseHandler
{
    private const BODY_KEYS = [
        'opportunity_ids', 'candidate_profile_id', 'scoring_rule_set_id', 'expires_at', 'idempotency_key',
    ];

    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly AssistantActionService $actions,
        private readonly DecisionDelegationService $delegations,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['decisions'];
    }

    public function params(): array
    {
        return [
            (new JsonInputParam('body', json_encode([
                'type' => 'object',
                'required' => self::BODY_KEYS,
                'additionalProperties' => false,
                'properties' => [
                    'opportunity_ids' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 500,
                        'uniqueItems' => true,
                        'items' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'candidate_profile_id' => ['type' => 'integer', 'minimum' => 1],
                    'scoring_rule_set_id' => ['type' => 'integer', 'minimum' => 1],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 200],
                ],
            ], JSON_THROW_ON_ERROR)))->setRequired(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || array_is_list($body) || array_diff(array_keys($body), self::BODY_KEYS) !== []) {
            return self::error(422, 'invalid_delegation', 'Údaje delegace nejsou platné.');
        }
        $opportunityIds = $body['opportunity_ids'] ?? null;
        $profileId = $body['candidate_profile_id'] ?? null;
        $ruleSetId = $body['scoring_rule_set_id'] ?? null;
        $expiresAt = $body['expires_at'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;
        if (!is_array($opportunityIds) || !array_is_list($opportunityIds)
            || array_any($opportunityIds, static fn (mixed $id): bool => !is_int($id))
            || !is_int($profileId) || !is_int($ruleSetId) || !is_string($expiresAt) || !is_string($idempotencyKey)
        ) {
            return self::error(422, 'invalid_delegation', 'Údaje delegace nejsou platné.');
        }

        try {
            $expiry = new \DateTimeImmutable($expiresAt);
            $identity = $this->requestContext->identity();
            $request = [
                'opportunity_ids' => $opportunityIds,
                'candidate_profile_id' => $profileId,
                'scoring_rule_set_id' => $ruleSetId,
                'expires_at' => $expiry->format(DATE_ATOM),
            ];
            $reservation = $this->actions->reserve(
                $identity,
                $idempotencyKey,
                'decision.create_delegation',
                'user',
                $identity->ownerUserId,
                $request,
            );
        } catch (\DateMalformedStringException|\InvalidArgumentException $exception) {
            return self::error(422, 'invalid_delegation', $exception->getMessage());
        }
        if (!$reservation->created) {
            if ($reservation->response !== null && $reservation->httpStatus !== null) {
                return new JsonApiResponse($reservation->httpStatus, $reservation->response);
            }
            return self::error(409, 'action_in_progress', 'Stejná idempotentní akce se právě zpracovává.');
        }

        try {
            /** @var list<int> $opportunityIds */
            $result = $this->delegations->create(
                $identity->ownerUserId,
                $opportunityIds,
                $profileId,
                $ruleSetId,
                $expiry,
            );
            $response = ['data' => [
                'id' => $result->id,
                'opportunity_ids' => $result->opportunityIds,
                'expires_at' => $result->expiresAt->format(DATE_ATOM),
                'status' => 'active',
                'application_submitted' => false,
            ]];
            $this->actions->complete($reservation->id, 201, $response);
            return new JsonApiResponse(201, $response);
        } catch (\InvalidArgumentException $exception) {
            $response = self::errorPayload('invalid_delegation', $exception->getMessage());
            $this->actions->reject($reservation->id, 'rejected', 422, $response);
            return new JsonApiResponse(422, $response);
        }
    }

    private static function error(int $status, string $code, string $message): JsonApiResponse
    {
        return new JsonApiResponse($status, self::errorPayload($code, $message));
    }

    /** @return array<string, array<string, string>> */
    private static function errorPayload(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message' => $message]];
    }
}
