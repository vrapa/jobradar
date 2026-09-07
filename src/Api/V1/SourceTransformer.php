<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Search\SourceView;

final class SourceTransformer
{
    /** @return array<string, mixed> */
    public function transform(SourceView $source): array
    {
        return [
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
            'last_attempt_at' => $source->lastAttemptAt?->format(DATE_ATOM),
            'last_success_at' => $source->lastSuccessAt?->format(DATE_ATOM),
            'last_found_at' => $source->lastFoundAt?->format(DATE_ATOM),
            'stale' => $source->stale,
        ];
    }
}
