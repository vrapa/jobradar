<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\Auth\ApiRequestContext;
use App\Search\RunnerDeviceService;
use App\Search\SearchRunService;
use App\Search\SourceRunResult;
use App\Search\SourceRunScope;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Params\GetInputParam;
use Tomaj\NetteApi\Params\JsonInputParam;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tomaj\NetteApi\Validation\InputType;

final class RecordSourceProgressHandler extends BaseHandler
{
    private const COUNTS = [
        'pages_traversed', 'displayed_count', 'detail_opened_count', 'stored_count',
        'updated_count', 'duplicate_count', 'rejected_count',
    ];

    public function __construct(
        private readonly ApiRequestContext $requestContext,
        private readonly RunnerDeviceService $devices,
        private readonly SearchRunService $runs,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['runner'];
    }

    public function params(): array
    {
        return [
            (new GetInputParam('id', InputType::INTEGER))->setRequired(),
            (new GetInputParam('sourceId', InputType::INTEGER))->setRequired(),
            (new JsonInputParam('body', json_encode([
                'type' => 'object',
                'required' => ['event', 'lease_token'],
                'properties' => [
                    'event' => ['type' => 'string', 'enum' => ['start', 'finish']],
                    'lease_token' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                    'scope' => ['type' => 'object'],
                    'result' => ['type' => 'object'],
                ],
                'additionalProperties' => false,
            ], JSON_THROW_ON_ERROR)))->setRequired(),
        ];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $runId = $params['id'] ?? null;
        $sourceId = $params['sourceId'] ?? null;
        $body = $params['body'] ?? null;
        if (!is_int($runId) || $runId < 1 || !is_int($sourceId) || $sourceId < 1 || !is_array($body)
            || !isset($body['event'], $body['lease_token']) || !is_string($body['event']) || !is_string($body['lease_token'])
        ) {
            return self::error(422, 'invalid_progress', 'Údaje průběhu nejsou platné.');
        }
        try {
            $deviceId = $this->devices->requireDeviceId($this->requestContext->identity());
            if ($body['event'] === 'start') {
                $this->runs->startRunSource($deviceId, $runId, $body['lease_token'], $sourceId, $this->scope($body['scope'] ?? null));
            } elseif ($body['event'] === 'finish') {
                $this->runs->finishRunSource($deviceId, $runId, $body['lease_token'], $sourceId, $this->result($body['result'] ?? null));
            } else {
                throw new \InvalidArgumentException('Událost průběhu musí být start nebo finish.');
            }
        } catch (\InvalidArgumentException $exception) {
            return self::error(409, 'invalid_progress_transition', $exception->getMessage());
        }
        return new JsonApiResponse(200, ['data' => [
            'run_id' => $runId,
            'source_id' => $sourceId,
            'event' => $body['event'],
        ]]);
    }

    private function scope(mixed $value): SourceRunScope
    {
        if (!is_array($value) || !isset($value['description']) || !is_string($value['description'])) {
            throw new \InvalidArgumentException('Zahájení zdroje musí obsahovat popis skutečného rozsahu.');
        }
        $allowed = ['description', 'filters', 'horizon_from', 'horizon_to'];
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new \InvalidArgumentException('Rozsah obsahuje neznámá pole.');
        }
        $filters = $value['filters'] ?? [];
        if (!is_array($filters)) {
            throw new \InvalidArgumentException('Filtry rozsahu musí být JSON objekt nebo pole.');
        }
        return new SourceRunScope(
            $value['description'],
            $filters,
            $this->date($value['horizon_from'] ?? null),
            $this->date($value['horizon_to'] ?? null),
        );
    }

    private function result(mixed $value): SourceRunResult
    {
        if (!is_array($value) || !isset($value['status']) || !is_string($value['status'])) {
            throw new \InvalidArgumentException('Ukončení zdroje musí obsahovat výsledný stav.');
        }
        $allowed = array_merge(['status', 'incomplete_reason', 'error_code'], self::COUNTS);
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new \InvalidArgumentException('Výsledek obsahuje neznámá pole.');
        }
        $counts = [];
        foreach (self::COUNTS as $name) {
            $count = $value[$name] ?? null;
            if ($count !== null && !is_int($count)) {
                throw new \InvalidArgumentException('Počty průchodu musí být celá čísla nebo null.');
            }
            $counts[$name] = $count;
        }
        $reason = $value['incomplete_reason'] ?? null;
        $errorCode = $value['error_code'] ?? null;
        if (($reason !== null && !is_string($reason)) || ($errorCode !== null && !is_string($errorCode))) {
            throw new \InvalidArgumentException('Důvod a kód chyby musí být textové hodnoty.');
        }
        return new SourceRunResult(
            $value['status'],
            $counts['pages_traversed'],
            $counts['displayed_count'],
            $counts['detail_opened_count'],
            $counts['stored_count'],
            $counts['updated_count'],
            $counts['duplicate_count'],
            $counts['rejected_count'],
            $reason,
            $errorCode,
        );
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Časový horizont musí být platný ISO-8601 čas.');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Časový horizont musí být platný ISO-8601 čas.');
        }
    }

    private static function error(int $status, string $code, string $message): JsonApiResponse
    {
        return new JsonApiResponse($status, ['error' => ['code' => $code, 'message' => $message]]);
    }
}
