<?php
declare(strict_types=1);
namespace App\Api\V1;

use App\Application\ApplicationWorkflowService;
use App\Api\Auth\ApiRequestContext;
use App\Opportunity\OpportunityConflictException;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class RecordApplicationEventHandler extends BaseHandler
{
    public function __construct(private readonly ApiRequestContext $context, private readonly ApplicationWorkflowService $workflow) { parent::__construct(); }
    public function tags(): array { return ['applications']; }
    public function params(): array { return [(new JsonInputParam('body', json_encode(self::schema(), JSON_THROW_ON_ERROR)))->setRequired()]; }
    /** @return array<string,mixed> */
    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['opportunity_id', 'event', 'expected_lock_version', 'idempotency_key', 'reference', 'occurred_at'],
            'properties' => [
                'opportunity_id' => ['type' => 'integer', 'minimum' => 1],
                'event' => ['type' => 'string', 'enum' => ['prepared', 'submitted', 'response_received', 'closed']],
                'expected_lock_version' => ['type' => 'integer', 'minimum' => 1],
                'idempotency_key' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 200],
                'reference' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
                'channel' => ['type' => 'string', 'enum' => ['portal', 'email', 'other']],
                'approval_reference' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'follow_up_at' => ['type' => 'string', 'format' => 'date-time'],
                'complete_action_item_ids' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'integer', 'minimum' => 1]],
            ]];
    }
    /** @param array<string,mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || !is_int($body['opportunity_id'] ?? null) || $body['opportunity_id'] < 1) { return new JsonApiResponse(422, ['error' => ['code' => 'invalid_application_event']]); }
        $id = $body['opportunity_id']; unset($body['opportunity_id']);
        try { return new JsonApiResponse(200, ['data' => $this->workflow->record($this->context->identity()->ownerUserId, $id, $body, 'assistant')]); }
        catch (OpportunityConflictException $e) { return new JsonApiResponse(409, ['error' => ['code' => 'application_conflict', 'message' => $e->getMessage()]]); }
        catch (\InvalidArgumentException $e) { return new JsonApiResponse(422, ['error' => ['code' => 'invalid_application_event', 'message' => $e->getMessage()]]); }
    }
}
