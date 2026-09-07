<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\AssistantActionService;
use App\Api\Auth\ApiRequestContext;
use App\Decision\DecisionBatchItem;
use App\Decision\DecisionDelegationService;
use App\Decision\OpportunityDecision;
use App\Opportunity\OpportunityConflictException;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class ApplyDecisionDelegationHandler extends BaseHandler
{
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
            (new GetInputParam('id', InputType::INTEGER))->setRequired(),
            (new JsonInputParam('body', json_encode([
                'type' => 'object',
                'required' => ['decisions', 'idempotency_key'],
                'additionalProperties' => false,
                'properties' => [
                    'decisions' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 500,
                        'items' => [
                            'type' => 'object',
                            'required' => ['opportunity_id', 'expected_lock_version', 'decision'],
                            'additionalProperties' => false,
                            'properties' => [
                                'opportunity_id' => ['type' => 'integer', 'minimum' => 1],
                                'expected_lock_version' => ['type' => 'integer', 'minimum' => 0],
                                'decision' => ['type' => 'string', 'enum' => ['undecided', 'react', 'uninteresting']],
                                'reason' => ['type' => ['string', 'null']],
                                'note' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 200],
                ],
            ], JSON_THROW_ON_ERROR)))->setRequired(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $delegationId = $params['id'] ?? null;
        $body = $params['body'] ?? null;
        if (!is_int($delegationId) || $delegationId < 1 || !is_array($body) || array_is_list($body)
            || array_diff(array_keys($body), ['decisions', 'idempotency_key']) !== []
        ) {
            return self::error(422, 'invalid_decision_batch', 'Údaje dávky rozhodnutí nejsou platné.');
        }
        $decisionData = $body['decisions'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;
        if (!is_array($decisionData) || !array_is_list($decisionData) || !is_string($idempotencyKey)) {
            return self::error(422, 'invalid_decision_batch', 'Údaje dávky rozhodnutí nejsou platné.');
        }

        try {
            $items = array_map(self::mapItem(...), $decisionData);
            $identity = $this->requestContext->identity();
            $reservation = $this->actions->reserve(
                $identity,
                $idempotencyKey,
                'decision.apply_delegation',
                'decision_delegation',
                $delegationId,
                ['decisions' => $decisionData],
            );
        } catch (\InvalidArgumentException|\ValueError $exception) {
            return self::error(422, 'invalid_decision_batch', $exception->getMessage());
        }
        if (!$reservation->created) {
            if ($reservation->response !== null && $reservation->httpStatus !== null) {
                return new JsonApiResponse($reservation->httpStatus, $reservation->response);
            }
            return self::error(409, 'action_in_progress', 'Stejná idempotentní akce se právě zpracovává.');
        }

        try {
            $result = $this->delegations->applyBatch($identity->ownerUserId, $delegationId, $items);
            $response = ['data' => [
                'delegation_id' => $result->delegationId,
                'processed' => $result->processed,
                'changed' => $result->changed,
                'status' => 'completed',
                'application_submitted' => false,
            ]];
            $this->actions->complete($reservation->id, 200, $response);
            return new JsonApiResponse(200, $response);
        } catch (OpportunityConflictException $exception) {
            $response = self::errorPayload('decision_batch_conflict', $exception->getMessage());
            $this->actions->reject($reservation->id, 'conflict', 409, $response);
            return new JsonApiResponse(409, $response);
        } catch (\InvalidArgumentException $exception) {
            $response = self::errorPayload('invalid_decision_batch', $exception->getMessage());
            $this->actions->reject($reservation->id, 'rejected', 422, $response);
            return new JsonApiResponse(422, $response);
        }
    }

    private static function mapItem(mixed $data): DecisionBatchItem
    {
        if (!is_array($data) || array_is_list($data)
            || array_diff(array_keys($data), ['opportunity_id', 'expected_lock_version', 'decision', 'reason', 'note']) !== []
        ) {
            throw new \InvalidArgumentException('Jedna položka dávky není platná.');
        }
        $opportunityId = $data['opportunity_id'] ?? null;
        $expectedLockVersion = $data['expected_lock_version'] ?? null;
        $decision = $data['decision'] ?? null;
        $reason = $data['reason'] ?? null;
        $note = $data['note'] ?? null;
        if (!is_int($opportunityId) || !is_int($expectedLockVersion) || !is_string($decision)
            || ($reason !== null && !is_string($reason)) || ($note !== null && !is_string($note))
        ) {
            throw new \InvalidArgumentException('Jedna položka dávky není platná.');
        }
        return new DecisionBatchItem(
            $opportunityId,
            $expectedLockVersion,
            OpportunityDecision::from($decision),
            $reason,
            $note,
        );
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
