<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Search\SourceQueryService;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class SourcesHandler extends BaseHandler
{
    public function __construct(private readonly SourceQueryService $sources)
    {
        parent::__construct();
    }

    public function summary(): string
    {
        return 'Seznam aktivních zdrojů dostupných pro ruční kontrolu.';
    }

    public function tags(): array
    {
        return ['sources'];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $items = array_map(
            static fn ($source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'url' => $source->url,
                'market_code' => $source->marketCode,
                'type' => $source->type,
                'priority' => $source->priority,
                'recommended_frequency_hours' => $source->recommendedFrequencyHours,
                'access' => [
                    'status' => $source->accessStatus,
                    'verified_at' => $source->accessVerifiedAt?->format(DATE_ATOM),
                    'intervention_required' => $source->interventionRequired,
                    'login_url' => $source->loginUrl,
                ],
            ],
            $this->sources->activeCheckableSources(),
        );
        return new JsonApiResponse(200, [
            'data' => $items,
            'meta' => ['count' => count($items)],
        ]);
    }
}
