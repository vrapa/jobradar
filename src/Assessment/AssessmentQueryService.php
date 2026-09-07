<?php

declare(strict_types=1);

namespace App\Assessment;

use Nette\Database\Connection;
use Nette\Database\Row;

final class AssessmentQueryService
{
    public function __construct(private readonly Connection $database)
    {
    }

    public function getCurrent(int $opportunityId): ?AssessmentView
    {
        $row = $this->database->fetch(
            'SELECT a.*, p.name AS profile_name, p.version AS profile_version,
                    r.name AS rule_name, r.version AS rule_version, r.status AS rule_status
             FROM assessments a
             INNER JOIN candidate_profiles p ON p.id = a.candidate_profile_id
             INNER JOIN scoring_rule_sets r ON r.id = a.scoring_rule_set_id
             WHERE a.opportunity_id = ? AND a.superseded_at IS NULL
             ORDER BY a.created_at DESC, a.id DESC LIMIT 1',
            $opportunityId,
        );
        if (!$row instanceof Row) {
            return null;
        }
        $assessmentId = (int) $row['id'];

        return new AssessmentView(
            id: $assessmentId,
            recommendation: AssessmentRecommendation::from((string) $row['recommendation']),
            coverage: self::decimal($row['coverage']),
            confidence: self::decimal($row['confidence']),
            summary: (string) $row['summary'],
            scoreMin: self::nullableDecimal($row['score_min']),
            scoreMax: self::nullableDecimal($row['score_max']),
            verifiedPoints: self::nullableDecimal($row['verified_points']),
            profileName: (string) $row['profile_name'],
            profileVersion: (int) $row['profile_version'],
            ruleSetName: (string) $row['rule_name'],
            ruleSetVersion: (int) $row['rule_version'],
            ruleSetStatus: (string) $row['rule_status'],
            authorType: (string) $row['author_type'],
            modelIdentifier: self::nullableString($row['model_identifier']),
            createdAt: self::dateTime($row['created_at']),
            breakdowns: $this->breakdowns($assessmentId),
            findings: $this->findings($assessmentId),
        );
    }

    /** @return list<AssessmentBreakdownView> */
    private function breakdowns(int $assessmentId): array
    {
        return array_map(
            static fn (Row $row): AssessmentBreakdownView => new AssessmentBreakdownView(
                area: (string) $row['area'],
                weight: self::nullableDecimal($row['weight']),
                scoreMin: self::nullableDecimal($row['score_min']),
                scoreMax: self::nullableDecimal($row['score_max']),
                evidence: self::nullableString($row['evidence']),
                explanation: (string) $row['explanation'],
            ),
            $this->database->fetchAll(
                'SELECT * FROM assessment_breakdowns WHERE assessment_id = ? ORDER BY id',
                $assessmentId,
            ),
        );
    }

    /** @return list<AssessmentFindingView> */
    private function findings(int $assessmentId): array
    {
        return array_map(
            static fn (Row $row): AssessmentFindingView => new AssessmentFindingView(
                type: (string) $row['finding_type'],
                severity: (string) $row['severity'],
                text: (string) $row['finding_text'],
                evidenceReference: self::nullableString($row['evidence_reference']),
            ),
            $this->database->fetchAll(
                'SELECT * FROM assessment_findings WHERE assessment_id = ? ORDER BY id',
                $assessmentId,
            ),
        );
    }

    private static function decimal(mixed $value): string
    {
        return (string) (float) $value;
    }

    private static function nullableDecimal(mixed $value): ?string
    {
        return $value === null ? null : self::decimal($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function dateTime(mixed $value): \DateTimeInterface
    {
        if (!$value instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('Databáze vrátila neplatné datum posouzení.');
        }
        return $value;
    }
}
