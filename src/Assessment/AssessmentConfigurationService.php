<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;

final class AssessmentConfigurationService
{
    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function createDraft(AssessmentConfigurationInput $input, ?int $actorUserId = null): AssessmentConfigurationResult
    {
        /** @var AssessmentConfigurationResult */
        return $this->database->transaction(function () use ($input, $actorUserId): AssessmentConfigurationResult {
            if ($this->database->fetchField(
                'SELECT id FROM candidate_profiles WHERE name = ? AND version = ?',
                $input->profileName,
                $input->profileVersion,
            ) !== null) {
                throw new \InvalidArgumentException('Tato verze profilu již existuje; existující verze jsou neměnné.');
            }
            if ($this->database->fetchField(
                'SELECT id FROM scoring_rule_sets WHERE name = ? AND version = ?',
                $input->ruleSetName,
                $input->ruleSetVersion,
            ) !== null) {
                throw new \InvalidArgumentException('Tato verze pravidel již existuje; existující verze jsou neměnné.');
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $this->database->query('INSERT INTO candidate_profiles', [
                'name' => $input->profileName,
                'version' => $input->profileVersion,
                'description' => $input->profileDescription,
                'parameters_json' => json_encode($input->profileParameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'valid_from' => $now,
                'valid_until' => null,
                'created_by_user_id' => $actorUserId,
                'created_at' => $now,
            ]);
            $profileId = (int) $this->database->getInsertId();
            $this->database->query('INSERT INTO scoring_rule_sets', [
                'name' => $input->ruleSetName,
                'version' => $input->ruleSetVersion,
                'status' => 'draft',
                'rules_json' => json_encode($input->rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'description' => $input->ruleSetDescription,
                'created_by_user_id' => $actorUserId,
                'created_at' => $now,
                'activated_at' => null,
                'archived_at' => null,
            ]);
            $ruleSetId = (int) $this->database->getInsertId();
            $this->auditLogger->record('assessment.configuration_created', $actorUserId, [
                'candidate_profile_id' => $profileId,
                'scoring_rule_set_id' => $ruleSetId,
                'rule_status' => 'draft',
            ]);

            return new AssessmentConfigurationResult($profileId, $ruleSetId);
        });
    }
}
