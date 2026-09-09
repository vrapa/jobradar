<?php
declare(strict_types=1);
namespace App\Api\V1;
use App\Action\ActionItemService;
use App\Api\Auth\ApiRequestContext;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
final class AcknowledgeActionItemHandler extends BaseHandler
{
    public function __construct(private readonly ApiRequestContext $context, private readonly ActionItemService $actions) { parent::__construct(); }
    public function tags(): array { return ['action-items']; }
    public function params(): array { return [(new JsonInputParam('body', '{"type":"object","additionalProperties":false,"required":["action_item_id","status"],"properties":{"action_item_id":{"type":"integer","minimum":1},"status":{"type":"string","enum":["open","completed","cancelled"]}}'))->setRequired()]; }
    /** @param array<string,mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $body = $params['body'] ?? null;
        if (!is_array($body) || !is_int($body['action_item_id'] ?? null) || !is_string($body['status'] ?? null) || array_diff(array_keys($body), ['action_item_id', 'status']) !== []) { return new JsonApiResponse(422, ['error' => ['code' => 'invalid_action_item']]); }
        try { $this->actions->acknowledgeExternalStatus($this->context->identity()->ownerUserId, $body['action_item_id'], $body['status']); }
        catch (\InvalidArgumentException $e) { return new JsonApiResponse(422, ['error' => ['code' => 'invalid_action_item', 'message' => $e->getMessage()]]); }
        return new JsonApiResponse(200, ['data' => ['acknowledged' => true]]);
    }
}
