<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class OpportunityImportService
{
    private const MANUAL_SOURCE_NAME = 'Ruční import';

    public function __construct(
        private readonly Connection $database,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly AuditLogger $auditLogger,
        private readonly ProjectCareService $projectCare,
    ) {
    }

    public function import(OpportunityImport $import, ?int $actorUserId = null, ?int $sourceId = null): OpportunityImportResult
    {
        /** @var OpportunityImportResult */
        return $this->database->transaction(function () use ($import, $actorUserId, $sourceId): OpportunityImportResult {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $normalizedUrl = $this->urlNormalizer->normalize($import->url);
            $urlHash = hash('sha256', $normalizedUrl);
            $source = $sourceId === null
                ? $this->database->fetch('SELECT id FROM sources WHERE name = ?', self::MANUAL_SOURCE_NAME)
                : $this->database->fetch('SELECT id FROM sources WHERE id = ? AND active = 1 AND archived_at IS NULL', $sourceId);
            if ($import->discoveryDefinitionId !== null) {
                $discoverySource = $this->database->fetch("SELECT s.id FROM sources s JOIN source_search_definitions d ON d.source_id = s.id WHERE d.id = ? AND s.source_type = 'manual_search' AND s.active = 1 AND s.archived_at IS NULL", $import->discoveryDefinitionId);
                if ($discoverySource === null || ($sourceId !== null && (int) $discoverySource['id'] !== $sourceId)) { throw new \InvalidArgumentException('Definice nalezení neodpovídá ručnímu zdroji.'); }
                $source = $discoverySource;
            }
            if (!$source instanceof Row) {
                throw new \RuntimeException('Zdroj pro ruční import není inicializovaný. Spusťte databázové migrace.');
            }

            $companyId = $this->resolveCompanyId($import->companyName, $now);
            $opportunity = $this->database->fetch(
                'SELECT id, lock_version FROM opportunities WHERE canonical_url_hash = ?',
                $urlHash,
            );
            $opportunityCreated = !$opportunity instanceof Row;
            if ($opportunityCreated) {
                $this->database->query('INSERT INTO opportunities', [
                    'opportunity_type' => 'offer',
                    'company_id' => $companyId,
                    'canonical_url' => $normalizedUrl,
                    'canonical_url_hash' => $urlHash,
                    'validity_status' => 'unknown',
                    'found_at' => $now,
                    'lock_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $opportunityId = (int) $this->database->getInsertId();
            } else {
                $opportunityId = (int) $opportunity['id'];
                if ($companyId !== null) {
                    $this->database->query(
                        'UPDATE opportunities SET company_id = COALESCE(company_id, ?), updated_at = ? WHERE id = ?',
                        $companyId,
                        $now,
                        $opportunityId,
                    );
                }
            }

            $this->linkSource($opportunityId, (int) $source['id'], $normalizedUrl, $urlHash, $now);
            $contentHash = $this->contentHash($import);
            $version = $this->database->fetch(
                'SELECT id FROM source_versions WHERE opportunity_id = ? AND content_hash = ?',
                $opportunityId,
                $contentHash,
            );
            $versionCreated = !$version instanceof Row;
            if ($versionCreated) {
                $this->database->query('INSERT INTO source_versions', [
                    'opportunity_id' => $opportunityId,
                    'acquired_at' => $now,
                    'content_hash' => $contentHash,
                    'source_language' => $this->nullable($import->sourceLanguage),
                    'original_title' => trim($import->originalTitle),
                    'translated_title' => $this->nullable($import->translatedTitle),
                    'original_text' => trim($import->originalText),
                    'translated_text' => $this->nullable($import->translatedText),
                    'summary' => $this->nullable($import->summary),
                    'translation_status' => $this->hasTranslation($import) ? 'completed' : 'not_requested',
                    'translation_method' => $this->hasTranslation($import) ? ($sourceId === null ? 'manual' : 'codex') : null,
                    'incomplete' => $import->incomplete,
                    'created_at' => $now,
                ]);
                $versionId = (int) $this->database->getInsertId();
                $this->database->query(
                    'UPDATE opportunities SET current_source_version_id = ?, lock_version = lock_version + ?, updated_at = ? WHERE id = ?',
                    $versionId,
                    $opportunityCreated ? 0 : 1,
                    $now,
                    $opportunityId,
                );
            } else {
                $versionId = (int) $version['id'];
                $this->completeExistingVersion($versionId, $import, $sourceId === null ? 'manual' : 'codex');
            }

            $result = new OpportunityImportResult($opportunityId, $versionId, $opportunityCreated, $versionCreated);
            if ($import->projectCare !== null) {
                $this->projectCare->save($opportunityId, (int) $this->database->fetchField('SELECT lock_version FROM opportunities WHERE id = ?', $opportunityId), $import->projectCare, $actorUserId, $versionId);
            }
            if ($import->discoveryDefinitionId !== null) {
                $this->database->query('INSERT IGNORE INTO opportunity_discoveries', ['opportunity_id' => $opportunityId, 'search_definition_id' => $import->discoveryDefinitionId, 'discovered_at' => $now]);
            }
            $this->auditLogger->record($sourceId === null ? 'opportunity.manual_imported' : 'opportunity.source_imported', $actorUserId, [
                'opportunity_id' => $opportunityId,
                'source_version_id' => $versionId,
                'opportunity_created' => $opportunityCreated,
                'version_created' => $versionCreated,
            ]);

            return $result;
        });
    }

    private function completeExistingVersion(int $versionId, OpportunityImport $import, string $translationMethod): void
    {
        $translatedTitle = $this->nullable($import->translatedTitle);
        $translatedText = $this->nullable($import->translatedText);
        $summary = $this->nullable($import->summary);
        $sourceLanguage = $this->nullable($import->sourceLanguage);
        $hasTranslation = $this->hasTranslation($import);
        $this->database->query(
            'UPDATE source_versions SET
                source_language = COALESCE(?, source_language),
                translated_title = COALESCE(?, translated_title),
                translated_text = COALESCE(?, translated_text),
                summary = COALESCE(?, summary),
                translation_status = IF(?, ?, translation_status),
                translation_method = IF(?, ?, translation_method)
             WHERE id = ?',
            $sourceLanguage,
            $translatedTitle,
            $translatedText,
            $summary,
            $hasTranslation,
            'completed',
            $hasTranslation,
            $translationMethod,
            $versionId,
        );
    }

    private function resolveCompanyId(?string $companyName, \DateTimeImmutable $now): ?int
    {
        $companyName = $this->nullable($companyName);
        if ($companyName === null) {
            return null;
        }
        $normalizedName = mb_strtolower((string) preg_replace('/\s+/u', ' ', $companyName));
        $company = $this->database->fetch('SELECT id FROM companies WHERE normalized_name = ? AND archived_at IS NULL', $normalizedName);
        if ($company instanceof Row) {
            return (int) $company['id'];
        }
        $this->database->query('INSERT INTO companies', [
            'name' => $companyName,
            'normalized_name' => $normalizedName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->database->getInsertId();
    }

    private function linkSource(int $opportunityId, int $sourceId, string $url, string $urlHash, \DateTimeImmutable $now): void
    {
        $link = $this->database->fetch(
            'SELECT id FROM opportunity_sources WHERE source_id = ? AND normalized_url_hash = ?',
            $sourceId,
            $urlHash,
        );
        if ($link instanceof Row) {
            $this->database->query('UPDATE opportunity_sources SET last_seen_at = ? WHERE id = ?', $now, $link['id']);
            return;
        }
        $this->database->query('INSERT INTO opportunity_sources', [
            'opportunity_id' => $opportunityId,
            'source_id' => $sourceId,
            'external_id' => null,
            'url' => $url,
            'normalized_url_hash' => $urlHash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    private function contentHash(OpportunityImport $import): string
    {
        $content = trim($import->originalTitle) . "\n" . trim(str_replace(["\r\n", "\r"], "\n", $import->originalText));
        return hash('sha256', $content);
    }

    private function hasTranslation(OpportunityImport $import): bool
    {
        return $this->nullable($import->translatedTitle) !== null || $this->nullable($import->translatedText) !== null;
    }

    private function nullable(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        return $value === '' ? null : $value;
    }
}
