<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\AssistantActionService;
use App\Api\Auth\ApiRequestContext;
use App\Assessment\AssessmentPayloadMapper;
use App\Assessment\AssessmentService;
use App\Opportunity\OpportunityConflictException;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class SaveOpportunityAssessmentHandler extends BaseHandler
{
    private const BODY_KEYS = [
        'expectedLockVersion', 'candidateProfileId', 'scoringRuleSetId', 'recommendation',
        'coverage', 'confidence', 'summary', 'scoreMin', 'scoreMax', 'verifiedPoints',
        'modelIdentifier', 'breakdowns', 'findings', 'idempotencyKey',
    ];

    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly AssistantActionService $actions,
        private readonly AssessmentPayloadMapper $mapper,
        private readonly AssessmentService $assessments,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['assessments'];
    }

    public function params(): array
    {
        return [
            (new GetInputParam('id', InputType::INTEGER))->setRequired(),
            (new JsonInputParam('body', json_encode([
                'type' => 'object',
                'required' => [
                    'expectedLockVersion', 'candidateProfileId', 'scoringRuleSetId', 'recommendation',
                    'coverage', 'confidence', 'summary', 'modelIdentifier', 'idempotencyKey',
                ],
                'additionalProperties' => false,
                'properties' => [
                    'expectedLockVersion' => ['type' => 'integer', 'minimum' => 1],
                    'candidateProfileId' => ['type' => 'integer', 'minimum' => 1],
                    'scoringRuleSetId' => ['type' => 'integer', 'minimum' => 1],
                    'recommendation' => ['type' => 'string', 'enum' => ['react', 'uninteresting', 'verify']],
                    'coverage' => ['type' => 'string'],
                    'confidence' => ['type' => 'string'],
                    'summary' => ['type' => 'string', 'minLength' => 1],
                    'scoreMin' => ['type' => ['string', 'null']],
                    'scoreMax' => ['type' => ['string', 'null']],
                    'verifiedPoints' => ['type' => ['string', 'null']],
                    'modelIdentifier' => ['type' => 'string', 'minLength' => 1],
                    'breakdowns' => ['type' => 'array'],
                    'findings' => ['type' => 'array'],
                    'idempotencyKey' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 200],
                ],
            ], JSON_THROW_ON_ERROR)))->setRequired(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $opportunityId = $params['id'] ?? null;
        $body = $params['body'] ?? null;
        if (!is_int($opportunityId) || $opportunityId < 1 || !is_array($body) || array_is_list($body)) {
            return self::error(422, 'invalid_assessment', 'Údaje posouzení nejsou platné.');
        }
        if (array_diff(array_keys($body), self::BODY_KEYS) !== []) {
            return self::error(422, 'invalid_assessment', 'Posouzení obsahuje neznámá pole.');
        }
        $idempotencyKey = $body['idempotencyKey'] ?? null;
        if (!is_string($idempotencyKey)) {
            return self::error(422, 'invalid_assessment', 'Idempotency klíč musí být text.');
        }
        $assessmentData = $body;
        unset($assessmentData['idempotencyKey']);
        $assessmentData['opportunityId'] = $opportunityId;
        $assessmentData['authorType'] = 'assistant';

        try {
            $payload = $this->mapper->map(json_encode($assessmentData, JSON_THROW_ON_ERROR));
            $identity = $this->requestContext->identity();
            $reservation = $this->actions->reserve(
                $identity,
                $idempotencyKey,
                'opportunity.save_assessment',
                'opportunity',
                $opportunityId,
                $assessmentData,
            );
        } catch (\InvalidArgumentException|\JsonException|\ValueError $exception) {
            return self::error(422, 'invalid_assessment', $exception->getMessage());
        }
        if (!$reservation->created) {
            if ($reservation->response !== null && $reservation->httpStatus !== null) {
                return new JsonApiResponse($reservation->httpStatus, $reservation->response);
            }
            return self::error(409, 'action_in_progress', 'Stejná idempotentní akce se právě zpracovává.');
        }

        try {
            $result = $this->assessments->save(
                $payload->opportunityId,
                $payload->expectedLockVersion,
                $payload->assessment,
                $payload->breakdowns,
                $payload->findings,
            );
            $response = ['data' => [
                'opportunity_id' => $opportunityId,
                'assessment_id' => $result->assessmentId,
                'recommendation_id' => $result->recommendationId,
                'opportunity_lock_version' => $result->opportunityLockVersion,
                'decision_changed' => false,
                'application_submitted' => false,
            ]];
            $this->actions->complete($reservation->id, 201, $response);
            return new JsonApiResponse(201, $response);
        } catch (OpportunityConflictException $exception) {
            $response = self::errorPayload('assessment_conflict', $exception->getMessage());
            $this->actions->reject($reservation->id, 'conflict', 409, $response);
            return new JsonApiResponse(409, $response);
        } catch (\InvalidArgumentException $exception) {
            $response = self::errorPayload('invalid_assessment', $exception->getMessage());
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
