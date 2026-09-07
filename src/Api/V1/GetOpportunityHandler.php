<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Assessment\AssessmentQueryService;
use App\Opportunity\OpportunityQueryService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class GetOpportunityHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly OpportunityQueryService $opportunities,
        private readonly OpportunityTransformer $transformer,
        private readonly AssessmentQueryService $assessments,
        private readonly AssessmentTransformer $assessmentTransformer,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['opportunities'];
    }

    public function params(): array
    {
        return [(new GetInputParam('id', InputType::INTEGER))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $id = $params['id'] ?? null;
        if (!is_int($id) || $id < 1) {
            return new JsonApiResponse(422, ['error' => ['code' => 'invalid_opportunity_id', 'message' => 'ID nabídky není platné.']]);
        }
        $opportunity = $this->opportunities->getDetail($id, $this->requestContext->identity()->ownerUserId);
        if ($opportunity === null) {
            return new JsonApiResponse(404, ['error' => ['code' => 'opportunity_not_found', 'message' => 'Nabídka nebyla nalezena.']]);
        }
        $data = $this->transformer->transformDetail($opportunity);
        $assessment = $this->assessments->getCurrent($id);
        $data['current_assessment'] = $assessment === null ? null : $this->assessmentTransformer->transform($assessment);
        return new JsonApiResponse(200, ['data' => $data]);
    }
}
