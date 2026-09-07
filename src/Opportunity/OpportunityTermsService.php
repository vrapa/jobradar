<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class OpportunityTermsService
{
    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param list<TechnologyInput> $technologies */
    public function save(
        int $opportunityId,
        int $expectedLockVersion,
        OpportunityTermsInput $terms,
        array $technologies,
        ?int $actorUserId = null,
    ): int {
        /** @var int */
        return $this->database->transaction(function () use (
            $opportunityId,
            $expectedLockVersion,
            $terms,
            $technologies,
            $actorUserId,
        ): int {
            $opportunity = $this->database->fetch(
                'SELECT current_source_version_id, lock_version FROM opportunities WHERE id = ? AND archived_at IS NULL FOR UPDATE',
                $opportunityId,
            );
            if (!$opportunity instanceof Row || $opportunity['current_source_version_id'] === null) {
                throw new \InvalidArgumentException('Nabídka nebyla nalezena.');
            }
            if ((int) $opportunity['lock_version'] !== $expectedLockVersion) {
                throw new OpportunityConflictException('Nabídka byla mezitím změněna. Načtěte ji znovu.');
            }

            $versionId = (int) $opportunity['current_source_version_id'];
            $this->saveTerms($opportunityId, $versionId, $terms);
            $this->replaceTechnologies($opportunityId, $versionId, $technologies);
            $this->database->query(
                'UPDATE opportunities SET lock_version = lock_version + 1, updated_at = ? WHERE id = ?',
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                $opportunityId,
            );
            $newLockVersion = $expectedLockVersion + 1;
            $this->auditLogger->record('opportunity.terms_saved', $actorUserId, [
                'opportunity_id' => $opportunityId,
                'source_version_id' => $versionId,
                'technology_count' => count($technologies),
                'lock_version' => $newLockVersion,
            ]);

            return $newLockVersion;
        });
    }

    private function saveTerms(int $opportunityId, int $versionId, OpportunityTermsInput $terms): void
    {
        $values = [
            'opportunity_id' => $opportunityId,
            'source_version_id' => $versionId,
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
            'work_from_czechia' => $terms->workFromCzechia === null ? null : ($terms->workFromCzechia ? 'yes' : 'no'),
            'location' => $terms->location,
            'work_timezone' => $terms->workTimezone,
            'working_language' => $terms->workingLanguage,
            'communication_mode' => $terms->communicationMode,
            'verified_at' => null,
        ];
        $existing = $this->database->fetchField('SELECT id FROM opportunity_terms WHERE source_version_id = ?', $versionId);
        if ($existing === null) {
            $this->database->query('INSERT INTO opportunity_terms', $values);
            return;
        }
        unset($values['opportunity_id'], $values['source_version_id']);
        $this->database->query('UPDATE opportunity_terms SET', $values, 'WHERE id = ?', $existing);
    }

    /** @param list<TechnologyInput> $technologies */
    private function replaceTechnologies(int $opportunityId, int $versionId, array $technologies): void
    {
        $unique = [];
        foreach ($technologies as $technology) {
            if (isset($unique[$technology->normalizedName])) {
                throw new \InvalidArgumentException(sprintf('Technologie „%s“ je uvedena vícekrát.', $technology->name));
            }
            $unique[$technology->normalizedName] = true;
        }

        $this->database->query('DELETE FROM technology_requirements WHERE source_version_id = ?', $versionId);
        foreach ($technologies as $technology) {
            $this->database->query('INSERT INTO technology_requirements', [
                'opportunity_id' => $opportunityId,
                'source_version_id' => $versionId,
                'technology_name' => $technology->name,
                'normalized_name' => $technology->normalizedName,
                'requirement_level' => $technology->requirementLevel,
                'proven_experience' => $technology->provenExperience,
                'scoring_relevance' => $technology->scoringRelevance,
                'evidence' => $technology->evidence,
            ]);
        }
    }
}
