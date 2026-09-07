<?php

declare(strict_types=1);

namespace App\Opportunity;

use Nette\Database\Connection;
use Nette\Database\Row;

final class OpportunityQueryService
{
    public function __construct(private readonly Connection $database)
    {
    }

    /** @return list<OpportunitySummary> */
    public function listCurrent(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT o.id, o.validity_status, o.found_at, c.name AS company_name,
                    v.original_title, v.translated_title, v.summary, v.incomplete
             FROM opportunities o
             INNER JOIN source_versions v ON v.id = o.current_source_version_id
             LEFT JOIN companies c ON c.id = o.company_id
             WHERE o.archived_at IS NULL
             ORDER BY o.found_at DESC, o.id DESC',
        );

        return array_map(
            static fn (Row $row): OpportunitySummary => new OpportunitySummary(
                id: (int) $row['id'],
                title: (string) ($row['translated_title'] ?: $row['original_title']),
                companyName: self::nullableString($row['company_name']),
                summary: self::nullableString($row['summary']),
                validityStatus: (string) $row['validity_status'],
                incomplete: (bool) $row['incomplete'],
                foundAt: self::dateTime($row['found_at']),
            ),
            $rows,
        );
    }

    public function getDetail(int $id): ?OpportunityDetail
    {
        $row = $this->database->fetch(
            'SELECT o.id, o.canonical_url, o.validity_status, o.found_at, o.current_source_version_id, o.lock_version, c.name AS company_name,
                    v.original_title, v.translated_title, v.original_text, v.translated_text,
                    v.summary, v.source_language, v.incomplete, v.acquired_at,
                    (SELECT COUNT(*) FROM source_versions versions WHERE versions.opportunity_id = o.id) AS version_count
             FROM opportunities o
             INNER JOIN source_versions v ON v.id = o.current_source_version_id
             LEFT JOIN companies c ON c.id = o.company_id
             WHERE o.id = ? AND o.archived_at IS NULL',
            $id,
        );
        if (!$row instanceof Row) {
            return null;
        }

        $originalTitle = (string) $row['original_title'];
        $translatedTitle = self::nullableString($row['translated_title']);
        $versions = $this->loadVersions($id, (int) $row['current_source_version_id']);
        $terms = $this->loadTerms((int) $row['current_source_version_id']);
        $technologies = $this->loadTechnologies((int) $row['current_source_version_id']);

        return new OpportunityDetail(
            id: (int) $row['id'],
            title: $translatedTitle ?? $originalTitle,
            originalTitle: $originalTitle,
            translatedTitle: $translatedTitle,
            originalText: (string) $row['original_text'],
            translatedText: self::nullableString($row['translated_text']),
            summary: self::nullableString($row['summary']),
            companyName: self::nullableString($row['company_name']),
            canonicalUrl: (string) $row['canonical_url'],
            validityStatus: (string) $row['validity_status'],
            sourceLanguage: self::nullableString($row['source_language']),
            incomplete: (bool) $row['incomplete'],
            foundAt: self::dateTime($row['found_at']),
            acquiredAt: self::dateTime($row['acquired_at']),
            versionCount: (int) $row['version_count'],
            versions: $versions,
            lockVersion: (int) $row['lock_version'],
            terms: $terms,
            technologies: $technologies,
        );
    }

    private function loadTerms(int $versionId): ?OpportunityTermsView
    {
        $row = $this->database->fetch('SELECT * FROM opportunity_terms WHERE source_version_id = ?', $versionId);
        if (!$row instanceof Row) {
            return null;
        }

        return new OpportunityTermsView(
            rateMin: self::nullableString($row['rate_min']),
            rateMax: self::nullableString($row['rate_max']),
            currency: self::nullableString($row['currency']),
            rateUnit: self::nullableString($row['rate_unit']),
            engagementMode: self::nullableString($row['engagement_mode']),
            rateSource: self::nullableString($row['rate_source']),
            rateConfidence: self::nullableString($row['rate_confidence']),
            workloadMin: self::nullableString($row['workload_min']),
            workloadMax: self::nullableString($row['workload_max']),
            workloadUnit: self::nullableString($row['workload_unit']),
            durationText: self::nullableString($row['duration_text']),
            remoteMode: self::nullableString($row['remote_mode']),
            workFromCzechia: match ($row['work_from_czechia']) {
                'yes' => true,
                'no' => false,
                default => null,
            },
            location: self::nullableString($row['location']),
            workTimezone: self::nullableString($row['work_timezone']),
            workingLanguage: self::nullableString($row['working_language']),
            communicationMode: self::nullableString($row['communication_mode']),
            verifiedAt: self::nullableDateTime($row['verified_at']),
        );
    }

    /** @return list<TechnologyView> */
    private function loadTechnologies(int $versionId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT technology_name, requirement_level, proven_experience, scoring_relevance, evidence
             FROM technology_requirements WHERE source_version_id = ? ORDER BY technology_name',
            $versionId,
        );

        return array_map(
            static fn (Row $row): TechnologyView => new TechnologyView(
                name: (string) $row['technology_name'],
                requirementLevel: (string) $row['requirement_level'],
                provenExperience: $row['proven_experience'] === null ? null : (bool) $row['proven_experience'],
                scoringRelevance: self::nullableString($row['scoring_relevance']),
                evidence: self::nullableString($row['evidence']),
            ),
            $rows,
        );
    }

    /** @return list<OpportunityVersion> */
    private function loadVersions(int $opportunityId, int $currentVersionId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, original_title, translated_title, original_text, translated_text, incomplete, acquired_at
             FROM source_versions
             WHERE opportunity_id = ?
             ORDER BY acquired_at DESC, id DESC',
            $opportunityId,
        );

        return array_map(
            static fn (Row $row): OpportunityVersion => new OpportunityVersion(
                id: (int) $row['id'],
                originalTitle: (string) $row['original_title'],
                translatedTitle: self::nullableString($row['translated_title']),
                originalText: (string) $row['original_text'],
                translatedText: self::nullableString($row['translated_text']),
                incomplete: (bool) $row['incomplete'],
                acquiredAt: self::dateTime($row['acquired_at']),
                current: (int) $row['id'] === $currentVersionId,
            ),
            $rows,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function dateTime(mixed $value): \DateTimeInterface
    {
        if (!$value instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('Databáze vrátila neplatné datum nabídky.');
        }

        return $value;
    }

    private static function nullableDateTime(mixed $value): ?\DateTimeInterface
    {
        return $value === null ? null : self::dateTime($value);
    }
}
