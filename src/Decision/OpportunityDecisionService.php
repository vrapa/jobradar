<?php

declare(strict_types=1);

namespace App\Decision;

use App\Infrastructure\AuditLogger;
use App\Action\ActionItemService;
use App\Opportunity\OpportunityConflictException;
use Nette\Database\Connection;
use Nette\Database\Row;

final class OpportunityDecisionService
{
    private const UNINTERESTING_REASONS = [
        'low_rate', 'workload', 'not_remote', 'language_communication', 'technology',
        'wordpress_small_web', 'expired', 'duplicate', 'other',
    ];

    public function __construct(
        private readonly Connection $database,
        private readonly AuditLogger $auditLogger,
        private readonly ActionItemService $actionItems,
    ) {
    }

    public function setManualDecision(
        int $userId,
        int $opportunityId,
        int $expectedLockVersion,
        OpportunityDecision $decision,
        ?string $reason = null,
        ?string $note = null,
    ): DecisionResult {
        return $this->setDecision(
            $userId,
            $opportunityId,
            $expectedLockVersion,
            $decision,
            $reason,
            $note,
            'user',
            $userId,
            null,
        );
    }

    public function setAssistantDecision(
        int $userId,
        int $opportunityId,
        int $expectedLockVersion,
        OpportunityDecision $decision,
        ?string $reason = null,
        ?string $note = null,
        ?int $delegationId = null,
    ): DecisionResult {
        return $this->setDecision(
            $userId,
            $opportunityId,
            $expectedLockVersion,
            $decision,
            $reason,
            $note,
            'assistant',
            null,
            $delegationId,
        );
    }

    private function setDecision(
        int $userId,
        int $opportunityId,
        int $expectedLockVersion,
        OpportunityDecision $decision,
        ?string $reason,
        ?string $note,
        string $actorType,
        ?int $actorUserId,
        ?int $delegationId,
    ): DecisionResult {
        $reason = $this->normalizeReason($decision, $reason);
        $note = $this->nullable($note);

        /** @var DecisionResult */
        return $this->database->transaction(function () use (
            $userId,
            $opportunityId,
            $expectedLockVersion,
            $decision,
            $reason,
            $note,
            $actorType,
            $actorUserId,
            $delegationId,
        ): DecisionResult {
            // Lock the owner before offer state to serialize shared preparation plans.
            $this->database->fetch("SELECT id FROM users WHERE id = ? FOR UPDATE", $userId);
            if (!$this->database->fetchField(
                'SELECT id FROM opportunities WHERE id = ? AND archived_at IS NULL FOR SHARE',
                $opportunityId,
            )) {
                throw new \InvalidArgumentException('Nabídka nebyla nalezena.');
            }
            $state = $this->database->fetch(
                'SELECT decision, decision_reason, decision_note, lock_version
                 FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ? FOR UPDATE',
                $userId,
                $opportunityId,
            );
            $currentLockVersion = $state instanceof Row ? (int) $state['lock_version'] : 0;
            if ($currentLockVersion !== $expectedLockVersion) {
                throw new OpportunityConflictException('Rozhodnutí bylo mezitím změněno. Načtěte nabídku znovu.');
            }
            $previous = $state instanceof Row
                ? OpportunityDecision::from((string) $state['decision'])
                : OpportunityDecision::Undecided;
            if ($state instanceof Row
                && $previous === $decision
                && $this->nullable($state['decision_reason']) === $reason
                && $this->nullable($state['decision_note']) === $note
            ) {
                return new DecisionResult($previous, $decision, $currentLockVersion, false);
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $newLockVersion = $currentLockVersion + 1;
            $values = [
                'decision' => $decision->value,
                'decision_reason' => $reason,
                'decision_note' => $note,
                'decided_at' => $decision === OpportunityDecision::Undecided ? null : $now,
                'updated_at' => $now,
                'lock_version' => $newLockVersion,
            ];
            if ($state instanceof Row) {
                $this->database->query(
                    'UPDATE user_opportunity_state SET',
                    $values,
                    'WHERE user_id = ? AND opportunity_id = ?',
                    $userId,
                    $opportunityId,
                );
            } else {
                $this->database->query('INSERT INTO user_opportunity_state', [
                    'user_id' => $userId,
                    'opportunity_id' => $opportunityId,
                    ...$values,
                ]);
            }
            $this->database->query('INSERT INTO opportunity_decision_history', [
                'user_id' => $userId,
                'opportunity_id' => $opportunityId,
                'previous_decision' => $previous->value,
                'new_decision' => $decision->value,
                'reason' => $reason,
                'note' => $note,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'delegation_id' => $delegationId,
                'created_at' => $now,
            ]);
            $this->auditLogger->record('opportunity.decision_changed', $userId, [
                'opportunity_id' => $opportunityId,
                'previous_decision' => $previous->value,
                'new_decision' => $decision->value,
                'reason' => $reason,
                'state_lock_version' => $newLockVersion,
                'actor_type' => $actorType,
                'delegation_id' => $delegationId,
            ]);

            if ($decision === OpportunityDecision::React && $previous !== OpportunityDecision::React
                && in_array($this->database->fetchField(
                    'SELECT workflow_status FROM user_opportunity_state WHERE user_id = ? AND opportunity_id = ?',
                    $userId, $opportunityId,
                ), ['none', 'preparing'], true)) {
                $this->actionItems->create($userId, null, 'prepare_applications', 'Připravit reakce na nabídky v JobRadaru',
                    'V JobRadaru otevři nabídky K reakci. V soukromém pracovním projektu připrav reakce na nabídky, které ještě nejsou připravené, a předlož je ke schválení. Aktuální výběr zůstává v JobRadaru. Dokončení úkolu nic neodesílá ani nemění stav žádostí.', origin: 'system');
            }

            return new DecisionResult($previous, $decision, $newLockVersion, true);
        });
    }

    private function normalizeReason(OpportunityDecision $decision, ?string $reason): ?string
    {
        $reason = $this->nullable($reason);
        if ($decision !== OpportunityDecision::Uninteresting) {
            return null;
        }
        if ($reason === null || !in_array($reason, self::UNINTERESTING_REASONS, true)) {
            throw new \InvalidArgumentException('U nezajímavé nabídky vyberte platný důvod.');
        }
        return $reason;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
