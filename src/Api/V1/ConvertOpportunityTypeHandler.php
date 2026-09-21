<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Opportunity\CompanyLeadInput;
use App\Opportunity\OpportunityConflictException;
use App\Opportunity\OpportunityType;
use App\Opportunity\OpportunityTypeService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class ConvertOpportunityTypeHandler extends BaseHandler
{
    public function __construct(private readonly ApiRequestContext $context, private readonly OpportunityTypeService $types)
    {
        parent::__construct();
    }

    public function tags(): array { return ['company-leads']; }

    public function params(): array
    {
        return [
            (new GetInputParam('id', InputType::INTEGER))->setRequired(),
            (new JsonInputParam('body', json_encode(self::schema(), JSON_THROW_ON_ERROR)))->setRequired(),
        ];
    }

    /** @return array<string,mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['opportunity_type', 'expected_lock_version'],
            'properties' => [
                'opportunity_type' => ['type' => 'string', 'enum' => [OpportunityType::COMPANY_LEAD]],
                'expected_lock_version' => ['type' => 'integer', 'minimum' => 1],
                'company_lead' => CompanyLeadInput::schema(),
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $id = $params['id'] ?? null;
        $body = $params['body'] ?? null;
        if (!is_int($id) || $id < 1 || !is_array($body) || ($body['opportunity_type'] ?? null) !== OpportunityType::COMPANY_LEAD
            || !is_int($body['expected_lock_version'] ?? null)) {
            return self::error(422, 'invalid_opportunity_conversion', 'Údaje převodu nejsou platné.');
        }
        try {
            $details = CompanyLeadInput::fromPayload($body['company_lead'] ?? null);
            return new JsonApiResponse(200, ['data' => $this->types->convertOfferToCompanyLead($this->context->identity()->ownerUserId, $id, $body['expected_lock_version'], $details)]);
        } catch (OpportunityConflictException $exception) {
            return self::error(409, 'opportunity_conversion_conflict', $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return self::error(422, 'invalid_opportunity_conversion', $exception->getMessage());
        }
    }

    private static function error(int $status, string $code, string $message): JsonApiResponse
    {
        return new JsonApiResponse($status, ['error' => ['code' => $code, 'message' => $message]]);
    }
}
