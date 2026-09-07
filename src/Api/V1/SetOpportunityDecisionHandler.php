<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\AssistantActionService;
use App\Api\Auth\ApiRequestContext;
use App\Decision\OpportunityDecision;
use App\Decision\OpportunityDecisionService;
use App\Opportunity\OpportunityConflictException;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class SetOpportunityDecisionHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly AssistantActionService $actions,
        private readonly OpportunityDecisionService $decisions,
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
                'required' => ['expected_lock_version', 'decision', 'idempotency_key'],
                'additionalProperties' => false,
                'properties' => [
                    'expected_lock_version' => ['type' => 'integer', 'minimum' => 0],
                    'decision' => ['type' => 'string', 'enum' => ['undecided', 'react', 'uninteresting']],
                    'reason' => ['type' => ['string', 'null']],
                    'note' => ['type' => ['string', 'null']],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 200],
                ],
            ], JSON_THROW_ON_ERROR)))->setRequired(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $id = $params['id'] ?? null;
        $body = $params['body'] ?? null;
        if (!is_int($id) || $id < 1 || !is_array($body) || array_is_list($body)) {
            return self::error(422, 'invalid_decision', 'Údaje rozhodnutí nejsou platné.');
        }
        if (array_diff(array_keys($body), ['expected_lock_version', 'decision', 'reason', 'note', 'idempotency_key']) !== []) {
            return self::error(422, 'invalid_decision', 'Rozhodnutí obsahuje neznámá pole.');
        }
        $expectedLockVersion = $body['expected_lock_version'] ?? null;
        $decisionValue = $body['decision'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;
        $reason = $body['reason'] ?? null;
        $note = $body['note'] ?? null;
        if (!is_int($expectedLockVersion) || $expectedLockVersion < 0 || !is_string($decisionValue)
            || !is_string($idempotencyKey) || ($reason !== null && !is_string($reason)) || ($note !== null && !is_string($note))
        ) {
            return self::error(422, 'invalid_decision', 'Údaje rozhodnutí nejsou platné.');
        }

        try {
            $decision = OpportunityDecision::from($decisionValue);
            $identity = $this->requestContext->identity();
            $reservation = $this->actions->reserve(
                $identity,
                $idempotencyKey,
                'opportunity.set_decision',
                'opportunity',
                $id,
                [
                    'expected_lock_version' => $expectedLockVersion,
                    'decision' => $decision->value,
                    'reason' => $reason,
                    'note' => $note,
                ],
            );
        } catch (\ValueError|\InvalidArgumentException $exception) {
            return self::error(422, 'invalid_decision', $exception->getMessage());
        }
        if (!$reservation->created) {
            if ($reservation->response !== null && $reservation->httpStatus !== null) {
                return new JsonApiResponse($reservation->httpStatus, $reservation->response);
            }
            return self::error(409, 'action_in_progress', 'Stejná idempotentní akce se právě zpracovává.');
        }

        try {
            $result = $this->decisions->setAssistantDecision(
                $identity->ownerUserId,
                $id,
                $expectedLockVersion,
                $decision,
                $reason,
                $note,
            );
            $response = ['data' => [
                'opportunity_id' => $id,
                'previous_decision' => $result->previousDecision->value,
                'decision' => $result->decision->value,
                'lock_version' => $result->lockVersion,
                'changed' => $result->changed,
                'application_submitted' => false,
            ]];
            $this->actions->complete($reservation->id, 200, $response);
            return new JsonApiResponse(200, $response);
        } catch (OpportunityConflictException $exception) {
            $response = self::errorPayload('decision_conflict', $exception->getMessage());
            $this->actions->reject($reservation->id, 'conflict', 409, $response);
            return new JsonApiResponse(409, $response);
        } catch (\InvalidArgumentException $exception) {
            $response = self::errorPayload('invalid_decision', $exception->getMessage());
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
