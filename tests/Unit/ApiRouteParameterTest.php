<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\RouterFactory;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use PHPUnit\Framework\TestCase;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Validation\InputType;

final class ApiRouteParameterTest extends TestCase
{
    public function testOverflowingPathIdIsNotClampedToAnotherId(): void
    {
        $original = $_GET;
        try {
            $_GET = [];
            $id = (string) PHP_INT_MAX . '0';
            RouterFactory::createRouter()->match(new Request(new UrlScript(
                'https://jobradar.example.test/api/v1/search-requests/' . $id,
                '/index.php',
            )));
            self::assertSame($id, (new GetInputParam('id', InputType::INTEGER))->getValue());
        } finally {
            $_GET = $original;
        }
    }

    public function testPathIdsReachApiValidationAsIntegers(): void
    {
        $original = $_GET;
        try {
            foreach ([
                '/api/v1/search-requests/17' => ['id' => 17],
                '/api/v1/search-requests/17/resume' => ['id' => 17],
                '/api/v1/opportunities/23' => ['id' => 23],
                '/api/v1/search-runs/17/sources/23/progress' => ['id' => 17, 'sourceId' => 23],
            ] as $path => $expected) {
                $_GET = [];
                $route = RouterFactory::createRouter()->match(new Request(new UrlScript('https://jobradar.example.test' . $path, '/index.php')));
                self::assertNotNull($route);
                foreach ($expected as $key => $value) {
                    self::assertSame($value, (new GetInputParam($key, InputType::INTEGER))->getValue());
                }
            }
        } finally {
            $_GET = $original;
        }
    }
}
