<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Search\SearchRequestDetail;
use App\Search\SearchRunSourceView;

final class SearchRequestTransformer
{
    /** @return array<string, mixed> */
    public function transform(SearchRequestDetail $request): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status,
            'requested_at' => $request->requestedAt->format(DATE_ATOM),
            'completed_at' => $request->completedAt?->format(DATE_ATOM),
            'cancelled_at' => $request->cancelledAt?->format(DATE_ATOM),
            'run' => $request->runId === null ? null : [
                'id' => $request->runId,
                'status' => $request->runStatus,
                'started_at' => $request->startedAt?->format(DATE_ATOM),
                'finished_at' => $request->finishedAt?->format(DATE_ATOM),
                'completion_reason' => $request->completionReason,
                'runner_name' => $request->runnerName,
            ],
            'coverage' => [
                'planned_sources' => count($request->sources),
                'completely_checked_sources' => $request->checkedSourceCount(),
            ],
            'sources' => array_map($this->transformSource(...), $request->sources),
        ];
    }

    /** @return array<string, mixed> */
    private function transformSource(SearchRunSourceView $source): array
    {
        return [
            'id' => $source->sourceId,
            'name' => $source->sourceName,
            'priority' => $source->priority,
            'status' => $source->status,
            'started' => $source->wasStarted(),
            'checked_completely' => $source->wasCheckedCompletely(),
            'scope' => [
                'description' => $source->queryText,
                'horizon_from' => $source->horizonFrom?->format(DATE_ATOM),
                'horizon_to' => $source->horizonTo?->format(DATE_ATOM),
            ],
            'counts' => [
                'pages_traversed' => $source->pagesTraversed,
                'displayed' => $source->displayedCount,
                'details_opened' => $source->detailOpenedCount,
                'stored' => $source->storedCount,
                'updated' => $source->updatedCount,
                'duplicates' => $source->duplicateCount,
                'rejected' => $source->rejectedCount,
            ],
            'started_at' => $source->startedAt?->format(DATE_ATOM),
            'finished_at' => $source->finishedAt?->format(DATE_ATOM),
            'incomplete_reason' => $source->incompleteReason,
            'error_code' => $source->errorCode,
            'login_required' => $source->loginRequired,
            'login_url' => $source->loginUrl,
        ];
    }
}
