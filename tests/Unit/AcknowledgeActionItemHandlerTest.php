<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Api\V1\AcknowledgeActionItemHandler;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Validation\JsonSchemaValidator;

final class AcknowledgeActionItemHandlerTest extends TestCase
{
    public function testRequestSchemaAcceptsSupportedTodoistStatus(): void
    {
        $handler = (new \ReflectionClass(AcknowledgeActionItemHandler::class))->newInstanceWithoutConstructor();
        $params = $handler->params();

        self::assertCount(1, $params);
        self::assertInstanceOf(JsonInputParam::class, $params[0]);
        $input = json_decode('{"action_item_id":13,"status":"completed"}', false, 512, JSON_THROW_ON_ERROR);
        $result = (new JsonSchemaValidator())->validate($input, $params[0]->getSchema());

        self::assertTrue($result->isOk(), implode('; ', $result->getErrors()));
    }
}
