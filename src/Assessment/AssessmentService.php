<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Infrastructure\AuditLogger;
use App\Opportunity\OpportunityConflictException;
use Nette\Database\Connection;
use Nette\Database\Row;

final class AssessmentService
{
    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param list<AssessmentBreakdownInput> $breakdowns
     * @param list<AssessmentFindingInput> $findings
     */
    public function save(
        int $opportunityId,
        int $expectedLockVersion,
        AssessmentInput $input,
        array $breakdowns,
        array $findings,
    ): AssessmentResult {
        /** @var AssessmentResult */
        return $this->database->transaction(function () use (
            $opportunityId,
            $expectedLockVersion,
            $input,
            $breakdowns,
            $findings,
        ): AssessmentResult {
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
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->assertProfileAvailable($input->candidateProfileId, $now);
            $ruleStatus = $this->ruleStatus($input->scoringRuleSetId);
            $this->assertScoringAllowed($ruleStatus, $input, $breakdowns);
            $this->assertUniqueAreas($breakdowns);
            $versionId = (int) $opportunity['current_source_version_id'];

            $this->database->query(
                'UPDATE assessments SET superseded_at = ? WHERE opportunity_id = ? AND superseded_at IS NULL',
                $now,
                $opportunityId,
            );
            $this->database->query('INSERT INTO assessments', [
                'opportunity_id' => $opportunityId,
                'source_version_id' => $versionId,
                'candidate_profile_id' => $input->candidateProfileId,
                'scoring_rule_set_id' => $input->scoringRuleSetId,
                'score_min' => $input->scoreMin,
                'score_max' => $input->scoreMax,
                'verified_points' => $input->verifiedPoints,
                'coverage' => $input->coverage,
                'recommendation' => $input->recommendation->value,
                'confidence' => $input->confidence,
                'summary' => $input->summary,
                'author_type' => $input->authorType,
                'author_user_id' => $input->authorUserId,
                'model_identifier' => $this->nullable($input->modelIdentifier),
                'created_at' => $now,
                'superseded_at' => null,
            ]);
            $assessmentId = (int) $this->database->getInsertId();
            $this->insertBreakdowns($assessmentId, $breakdowns);
            $this->insertFindings($assessmentId, $findings, $now);

            $this->database->query(
                'UPDATE opportunity_recommendations SET superseded_at = ? WHERE opportunity_id = ? AND superseded_at IS NULL',
                $now,
                $opportunityId,
            );
            $this->database->query('INSERT INTO opportunity_recommendations', [
                'opportunity_id' => $opportunityId,
                'source_version_id' => $versionId,
                'assessment_id' => $assessmentId,
                'recommendation' => $input->recommendation->value,
                'reason' => $input->summary,
                'origin' => $input->authorType,
                'model_identifier' => $this->nullable($input->modelIdentifier),
                'client_identifier' => null,
                'valid_until' => null,
                'created_at' => $now,
                'superseded_at' => null,
            ]);
            $recommendationId = (int) $this->database->getInsertId();
            $newLockVersion = $expectedLockVersion + 1;
            $this->database->query(
                'UPDATE opportunities SET lock_version = ?, updated_at = ? WHERE id = ?',
                $newLockVersion,
                $now,
                $opportunityId,
            );
            $this->auditLogger->record('opportunity.assessment_saved', $input->authorUserId, [
                'opportunity_id' => $opportunityId,
                'source_version_id' => $versionId,
                'assessment_id' => $assessmentId,
                'recommendation' => $input->recommendation->value,
                'rule_status' => $ruleStatus,
                'finding_count' => count($findings),
                'breakdown_count' => count($breakdowns),
                'lock_version' => $newLockVersion,
            ]);

            return new AssessmentResult($assessmentId, $recommendationId, $newLockVersion);
        });
    }

    private function assertProfileAvailable(int $profileId, \DateTimeImmutable $now): void
    {
        $profile = $this->database->fetchField(
            'SELECT id FROM candidate_profiles WHERE id = ? AND valid_from <= ? AND (valid_until IS NULL OR valid_until > ?)',
            $profileId,
            $now,
            $now,
        );
        if ($profile === null) {
            throw new \InvalidArgumentException('Profil neexistuje nebo není v daném čase platný.');
        }
    }

    private function ruleStatus(int $ruleSetId): string
    {
        $status = $this->database->fetchField('SELECT status FROM scoring_rule_sets WHERE id = ?', $ruleSetId);
        if (!is_string($status) || $status === 'archived') {
            throw new \InvalidArgumentException('Pravidla neexistují nebo jsou archivovaná.');
        }
        return $status;
    }

    /** @param list<AssessmentBreakdownInput> $breakdowns */
    private function assertScoringAllowed(string $ruleStatus, AssessmentInput $input, array $breakdowns): void
    {
        $breakdownHasScore = array_any(
            $breakdowns,
            static fn (AssessmentBreakdownInput $item): bool => $item->scoreMin !== null || $item->scoreMax !== null,
        );
        if ($ruleStatus !== 'active' && ($input->hasNumericScore() || $breakdownHasScore)) {
            throw new \InvalidArgumentException('Číselné skóre lze uložit jen s aktivními schválenými pravidly.');
        }
    }

    /** @param list<AssessmentBreakdownInput> $breakdowns */
    private function assertUniqueAreas(array $breakdowns): void
    {
        $seen = [];
        foreach ($breakdowns as $breakdown) {
            $key = mb_strtolower($breakdown->area);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(sprintf('Oblast „%s“ je v rozkladu vícekrát.', $breakdown->area));
            }
            $seen[$key] = true;
        }
    }

    /** @param list<AssessmentBreakdownInput> $breakdowns */
    private function insertBreakdowns(int $assessmentId, array $breakdowns): void
    {
        foreach ($breakdowns as $breakdown) {
            $this->database->query('INSERT INTO assessment_breakdowns', [
                'assessment_id' => $assessmentId,
                'area' => $breakdown->area,
                'weight' => $breakdown->weight,
                'score_min' => $breakdown->scoreMin,
                'score_max' => $breakdown->scoreMax,
                'evidence' => $this->nullable($breakdown->evidence),
                'explanation' => $breakdown->explanation,
            ]);
        }
    }

    /** @param list<AssessmentFindingInput> $findings */
    private function insertFindings(int $assessmentId, array $findings, \DateTimeImmutable $now): void
    {
        foreach ($findings as $finding) {
            $this->database->query('INSERT INTO assessment_findings', [
                'assessment_id' => $assessmentId,
                'finding_type' => $finding->type,
                'severity' => $finding->severity,
                'finding_text' => $finding->text,
                'evidence_reference' => $this->nullable($finding->evidenceReference),
                'created_at' => $now,
            ]);
        }
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
