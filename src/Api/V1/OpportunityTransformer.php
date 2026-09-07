<?php

declare(strict_types=1);

namespace App\Api\V1;

use App\Opportunity\OpportunityDetail;
use App\Opportunity\OpportunitySummary;
use App\Opportunity\OpportunityTermsView;
use App\Opportunity\OpportunityVersion;
use App\Opportunity\TechnologyView;

final class OpportunityTransformer
{
    /** @return array<string, mixed> */
    public function transformSummary(OpportunitySummary $opportunity): array
    {
        return [
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'company_name' => $opportunity->companyName,
            'summary' => $opportunity->summary,
            'validity_status' => $opportunity->validityStatus,
            'incomplete' => $opportunity->incomplete,
            'found_at' => $opportunity->foundAt->format(DATE_ATOM),
            'decision' => $opportunity->decision->value,
            'workflow_status' => $opportunity->workflowStatus,
            'rate' => [
                'min' => $opportunity->rateMin,
                'max' => $opportunity->rateMax,
                'currency' => $opportunity->currency,
                'unit' => $opportunity->rateUnit,
            ],
            'workload' => [
                'min' => $opportunity->workloadMin,
                'max' => $opportunity->workloadMax,
                'unit' => $opportunity->workloadUnit,
            ],
            'assessment' => [
                'score_min' => $opportunity->scoreMin,
                'score_max' => $opportunity->scoreMax,
                'coverage_percent' => $opportunity->coveragePercent,
                'recommendation' => $opportunity->recommendation,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function transformDetail(OpportunityDetail $opportunity): array
    {
        return [
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'original_title' => $opportunity->originalTitle,
            'translated_title' => $opportunity->translatedTitle,
            'original_text' => $opportunity->originalText,
            'translated_text' => $opportunity->translatedText,
            'summary' => $opportunity->summary,
            'company_name' => $opportunity->companyName,
            'canonical_url' => $opportunity->canonicalUrl,
            'validity_status' => $opportunity->validityStatus,
            'source_language' => $opportunity->sourceLanguage,
            'incomplete' => $opportunity->incomplete,
            'found_at' => $opportunity->foundAt->format(DATE_ATOM),
            'decision' => $opportunity->decisionState->decision->value,
            'workflow_status' => $opportunity->decisionState->workflowStatus,
            'rate' => [
                'min' => $opportunity->terms?->rateMin,
                'max' => $opportunity->terms?->rateMax,
                'currency' => $opportunity->terms?->currency,
                'unit' => $opportunity->terms?->rateUnit,
            ],
            'workload' => [
                'min' => $opportunity->terms?->workloadMin,
                'max' => $opportunity->terms?->workloadMax,
                'unit' => $opportunity->terms?->workloadUnit,
            ],
            'assessment' => [
                'score_min' => null,
                'score_max' => null,
                'coverage_percent' => null,
                'recommendation' => null,
            ],
            'acquired_at' => $opportunity->acquiredAt->format(DATE_ATOM),
            'version_count' => $opportunity->versionCount,
            'lock_version' => $opportunity->lockVersion,
            'terms' => $opportunity->terms === null ? null : $this->transformTerms($opportunity->terms),
            'technologies' => array_map($this->transformTechnology(...), $opportunity->technologies),
            'versions' => array_map($this->transformVersion(...), $opportunity->versions),
            'decision_state' => [
                'decision' => $opportunity->decisionState->decision->value,
                'reason' => $opportunity->decisionState->reason,
                'note' => $opportunity->decisionState->note,
                'workflow_status' => $opportunity->decisionState->workflowStatus,
                'lock_version' => $opportunity->decisionState->lockVersion,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function transformTerms(OpportunityTermsView $terms): array
    {
        return [
            'rate_min' => $terms->rateMin,
            'rate_max' => $terms->rateMax,
            'currency' => $terms->currency,
            'rate_unit' => $terms->rateUnit,
            'engagement_mode' => $terms->engagementMode,
            'rate_source' => $terms->rateSource,
            'rate_confidence' => $terms->rateConfidence,
            'workload_min' => $terms->workloadMin,
            'workload_max' => $terms->workloadMax,
            'workload_unit' => $terms->workloadUnit,
            'duration_text' => $terms->durationText,
            'remote_mode' => $terms->remoteMode,
            'work_from_czechia' => $terms->workFromCzechia,
            'location' => $terms->location,
            'work_timezone' => $terms->workTimezone,
            'working_language' => $terms->workingLanguage,
            'communication_mode' => $terms->communicationMode,
            'verified_at' => $terms->verifiedAt?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function transformTechnology(TechnologyView $technology): array
    {
        return [
            'name' => $technology->name,
            'requirement_level' => $technology->requirementLevel,
            'proven_experience' => $technology->provenExperience,
            'scoring_relevance' => $technology->scoringRelevance,
            'evidence' => $technology->evidence,
        ];
    }

    /** @return array<string, mixed> */
    private function transformVersion(OpportunityVersion $version): array
    {
        return [
            'id' => $version->id,
            'original_title' => $version->originalTitle,
            'translated_title' => $version->translatedTitle,
            'original_text' => $version->originalText,
            'translated_text' => $version->translatedText,
            'incomplete' => $version->incomplete,
            'acquired_at' => $version->acquiredAt->format(DATE_ATOM),
            'current' => $version->current,
        ];
    }
}
