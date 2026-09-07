<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Search\SourceQueryService;
use App\Search\SourceView;
use Tomaj\NetteApi\Handlers\BaseHandler;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;

final class LoginRequiredSourcesHandler extends BaseHandler
{
    public function __construct(
        private readonly SourceQueryService $sources,
        private readonly SourceTransformer $transformer,
    ) {
        parent::__construct();
    }

    public function tags(): array
    {
        return ['sources'];
    }

    /** @param array<string, mixed> $params */
    public function handle(array $params): ResponseInterface
    {
        $sources = array_values(array_filter(
            $this->sources->activeCheckableSources(),
            static fn (SourceView $source): bool => $source->accessStatus === 'login_required' || $source->interventionRequired,
        ));
        return new JsonApiResponse(200, [
            'data' => array_map($this->transformer->transform(...), $sources),
            'meta' => ['count' => count($sources)],
        ]);
    }
}
