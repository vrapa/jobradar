<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Assessment\AssessmentBreakdownView;
use App\Assessment\AssessmentFindingView;
use App\Assessment\AssessmentView;

final class AssessmentTransformer
{
    /** @return array<string, mixed> */
    public function transform(AssessmentView $assessment): array
    {
        return [
            'id' => $assessment->id,
            'recommendation' => $assessment->recommendation->value,
            'coverage' => $assessment->coverage,
            'confidence' => $assessment->confidence,
            'summary' => $assessment->summary,
            'score_min' => $assessment->scoreMin,
            'score_max' => $assessment->scoreMax,
            'verified_points' => $assessment->verifiedPoints,
            'profile' => ['name' => $assessment->profileName, 'version' => $assessment->profileVersion],
            'rule_set' => [
                'name' => $assessment->ruleSetName,
                'version' => $assessment->ruleSetVersion,
                'status' => $assessment->ruleSetStatus,
            ],
            'author_type' => $assessment->authorType,
            'model_identifier' => $assessment->modelIdentifier,
            'created_at' => $assessment->createdAt->format(DATE_ATOM),
            'breakdowns' => array_map(self::transformBreakdown(...), $assessment->breakdowns),
            'findings' => array_map(self::transformFinding(...), $assessment->findings),
        ];
    }

    /** @return array<string, mixed> */
    private static function transformBreakdown(AssessmentBreakdownView $breakdown): array
    {
        return [
            'area' => $breakdown->area,
            'weight' => $breakdown->weight,
            'score_min' => $breakdown->scoreMin,
            'score_max' => $breakdown->scoreMax,
            'evidence' => $breakdown->evidence,
            'explanation' => $breakdown->explanation,
        ];
    }

    /** @return array<string, mixed> */
    private static function transformFinding(AssessmentFindingView $finding): array
    {
        return [
            'type' => $finding->type,
            'severity' => $finding->severity,
            'text' => $finding->text,
            'evidence_reference' => $finding->evidenceReference,
        ];
    }
}
