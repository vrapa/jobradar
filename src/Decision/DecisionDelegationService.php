<?php

declare(strict_types=1);

namespace App\Decision;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class DecisionDelegationService
{
    private const MAX_OPPORTUNITIES = 500;

    public function __construct(
        private readonly Connection $database,
        private readonly OpportunityDecisionService $decisions,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param list<int> $opportunityIds */
    public function create(
        int $userId,
        array $opportunityIds,
        int $candidateProfileId,
        int $scoringRuleSetId,
        \DateTimeImmutable $expiresAt,
    ): DecisionDelegationResult {
        $opportunityIds = array_values(array_unique($opportunityIds));
        sort($opportunityIds, SORT_NUMERIC);
        if ($opportunityIds === [] || count($opportunityIds) > self::MAX_OPPORTUNITIES
            || array_any($opportunityIds, static fn (int $id): bool => $id < 1)
        ) {
            throw new \InvalidArgumentException('Delegace musí obsahovat 1 až 500 platných ID nabídek.');
        }
        $now = self::now();
        $expiresAt = $expiresAt->setTimezone(new \DateTimeZone('UTC'));
        if ($expiresAt <= $now || $expiresAt > $now->modify('+7 days')) {
            throw new \InvalidArgumentException('Delegace musí vypršet v budoucnosti, nejpozději za sedm dnů.');
        }
        $this->assertProfileAndRules($candidateProfileId, $scoringRuleSetId, $now);
        $found = (int) $this->database->fetchField(
            'SELECT COUNT(*) FROM opportunities WHERE id IN (?) AND archived_at IS NULL',
            $opportunityIds,
        );
        if ($found !== count($opportunityIds)) {
            throw new \InvalidArgumentException('Jedna nebo více delegovaných nabídek neexistuje.');
        }
        $this->assertAssessedScope($opportunityIds, $candidateProfileId, $scoringRuleSetId);

        $this->database->query('INSERT INTO decision_delegations', [
            'requested_by_user_id' => $userId,
            'scope_type' => 'opportunity_ids',
            'scope_json' => json_encode(['opportunity_ids' => $opportunityIds], JSON_THROW_ON_ERROR),
            'candidate_profile_id' => $candidateProfileId,
            'scoring_rule_set_id' => $scoringRuleSetId,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);
        $id = (int) $this->database->getInsertId();
        $this->auditLogger->record('decision.delegation_created', $userId, [
            'delegation_id' => $id,
            'opportunity_count' => count($opportunityIds),
            'candidate_profile_id' => $candidateProfileId,
            'scoring_rule_set_id' => $scoringRuleSetId,
        ]);
        return new DecisionDelegationResult($id, $opportunityIds, $expiresAt);
    }

    /** @param list<DecisionBatchItem> $items */
    public function applyBatch(int $userId, int $delegationId, array $items): DecisionBatchResult
    {
        /** @var DecisionBatchResult */
        return $this->database->transaction(function () use ($userId, $delegationId, $items): DecisionBatchResult {
            $delegation = $this->database->fetch(
                'SELECT * FROM decision_delegations WHERE id = ? AND requested_by_user_id = ? FOR UPDATE',
                $delegationId,
                $userId,
            );
            $now = self::now();
            if (!$delegation instanceof Row || $delegation['status'] !== 'active'
                || !$delegation['expires_at'] instanceof \DateTimeInterface || $delegation['expires_at'] <= $now
            ) {
                throw new \InvalidArgumentException('Aktivní delegace nebyla nalezena nebo vypršela.');
            }
            $scopeIds = self::decodeScope($delegation['scope_json']);
            $itemIds = array_map(static fn (DecisionBatchItem $item): int => $item->opportunityId, $items);
            if (count($itemIds) !== count(array_unique($itemIds))) {
                throw new \InvalidArgumentException('Dávka obsahuje stejnou nabídku vícekrát.');
            }
            sort($itemIds, SORT_NUMERIC);
            if ($itemIds !== $scopeIds) {
                throw new \InvalidArgumentException('Dávka musí přesně odpovídat rozsahu delegace.');
            }
            $profileId = (int) $delegation['candidate_profile_id'];
            $ruleSetId = (int) $delegation['scoring_rule_set_id'];
            $this->assertProfileAndRules($profileId, $ruleSetId, $now);
            $this->assertAssessedScope($scopeIds, $profileId, $ruleSetId);

            $changed = 0;
            $results = [];
            foreach ($items as $item) {
                $result = $this->decisions->setAssistantDecision(
                    $userId,
                    $item->opportunityId,
                    $item->expectedLockVersion,
                    $item->decision,
                    $item->reason,
                    $item->note,
                    $delegationId,
                );
                $changed += (int) $result->changed;
                $results[$item->opportunityId] = $result;
            }
            $summary = ['processed' => count($items), 'changed' => $changed];
            $this->database->query('UPDATE decision_delegations SET', [
                'status' => 'completed',
                'result_summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                'completed_at' => $now,
            ], 'WHERE id = ?', $delegationId);
            $this->auditLogger->record('decision.delegation_completed', $userId, [
                'delegation_id' => $delegationId,
                'processed' => count($items),
                'changed' => $changed,
            ]);
            return new DecisionBatchResult($delegationId, count($items), $changed, $results);
        });
    }

    private function assertProfileAndRules(int $profileId, int $ruleSetId, \DateTimeImmutable $now): void
    {
        if ($this->database->fetchField(
            'SELECT id FROM candidate_profiles WHERE id = ? AND valid_from <= ? AND (valid_until IS NULL OR valid_until > ?)',
            $profileId,
            $now,
            $now,
        ) === null) {
            throw new \InvalidArgumentException('Profil delegace neexistuje nebo není platný.');
        }
        $rulesJson = $this->database->fetchField(
            "SELECT rules_json FROM scoring_rule_sets WHERE id = ? AND status = 'active' AND archived_at IS NULL",
            $ruleSetId,
        );
        if (!is_string($rulesJson)) {
            throw new \InvalidArgumentException('Delegované rozhodování vyžaduje aktivní schválená pravidla.');
        }
        $rules = json_decode($rulesJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($rules) || ($rules['financialCurveApproved'] ?? null) !== true) {
            throw new \InvalidArgumentException('Delegované rozhodování vyžaduje výslovně schválenou finanční křivku.');
        }
    }

    /** @param list<int> $opportunityIds */
    private function assertAssessedScope(array $opportunityIds, int $profileId, int $ruleSetId): void
    {
        $count = (int) $this->database->fetchField(
            'SELECT COUNT(*) FROM assessments
             WHERE opportunity_id IN (?) AND candidate_profile_id = ? AND scoring_rule_set_id = ? AND superseded_at IS NULL',
            $opportunityIds,
            $profileId,
            $ruleSetId,
        );
        if ($count !== count($opportunityIds)) {
            throw new \InvalidArgumentException('Každá delegovaná nabídka musí mít aktuální posouzení daným profilem a pravidly.');
        }
    }

    /** @return list<int> */
    private static function decodeScope(mixed $json): array
    {
        if (!is_string($json)) {
            throw new \UnexpectedValueException('Rozsah delegace není platný.');
        }
        $scope = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $ids = is_array($scope) ? ($scope['opportunity_ids'] ?? null) : null;
        if (!is_array($ids) || !array_is_list($ids) || array_any($ids, static fn (mixed $id): bool => !is_int($id))) {
            throw new \UnexpectedValueException('Rozsah delegace není platný.');
        }
        /** @var list<int> $ids */
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
