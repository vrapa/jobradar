<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Opportunity\OpportunityImportService;
use App\Opportunity\OpportunityJsonMapper;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class ImportOpportunityHandler extends BaseHandler
{
    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly OpportunityJsonMapper $mapper,
        private readonly OpportunityImportService $importer,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['opportunities'];
    }

    public function params(): array
    {
        return [(new JsonInputParam('body', json_encode([
            'type' => 'object',
            'required' => ['url', 'originalTitle', 'originalText'],
            'additionalProperties' => false,
            'properties' => [
                'url' => ['type' => 'string', 'format' => 'uri'],
                'originalTitle' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'originalText' => ['type' => 'string', 'minLength' => 1],
                'companyName' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'translatedTitle' => ['type' => ['string', 'null'], 'maxLength' => 500],
                'translatedText' => ['type' => ['string', 'null']],
                'summary' => ['type' => ['string', 'null'], 'maxLength' => 65535],
                'sourceLanguage' => ['type' => ['string', 'null'], 'maxLength' => 16],
                'incomplete' => ['type' => 'boolean'],
                'attachmentReviews' => \App\Opportunity\AttachmentReviewInput::schema(),
                'projectCare' => \App\Opportunity\ProjectCareInput::schema(),
                'counterparty' => \App\Opportunity\CounterpartyInput::schema(),
                'discoveryDefinitionId' => ['type' => ['integer','null'], 'minimum' => 1],
            ],
        ], JSON_THROW_ON_ERROR)))->setRequired()];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || array_is_list($body)) {
            return self::error('Požadavek musí obsahovat jeden JSON objekt nabídky.');
        }
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $imports = $this->mapper->map($json);
            if (count($imports) !== 1) {
                throw new \InvalidArgumentException('Požadavek musí obsahovat právě jednu nabídku.');
            }
            $result = $this->importer->import(
                $imports[0],
                $this->requestContext->identity()->ownerUserId,
            );
        } catch (\InvalidArgumentException|\JsonException $exception) {
            return self::error($exception->getMessage());
        }
        return new JsonApiResponse($result->opportunityCreated || $result->versionCreated ? 201 : 200, [
            'data' => [
                'opportunity_id' => $result->opportunityId,
                'source_version_id' => $result->sourceVersionId,
                'opportunity_created' => $result->opportunityCreated,
                'version_created' => $result->versionCreated,
            ],
        ]);
    }

    private static function error(string $message): JsonApiResponse
    {
        return new JsonApiResponse(422, ['error' => ['code' => 'invalid_opportunity', 'message' => $message]]);
    }
}
