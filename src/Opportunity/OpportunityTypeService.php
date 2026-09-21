<?php

declare(strict_types=1);

namespace App\Opportunity;

use App\Infrastructure\AuditLogger;
use Nette\Database\Connection;
use Nette\Database\Row;

final class OpportunityTypeService
{
    public function __construct(private readonly Connection $database, private readonly AuditLogger $audit)
    {
    }

    /** @return array{opportunity_id:int,opportunity_type:string,lock_version:int,changed:bool} */
    public function convertOfferToCompanyLead(int $userId, int $opportunityId, int $expectedLockVersion, ?CompanyLeadInput $details = null): array
    {
        return $this->database->transaction(function () use ($userId, $opportunityId, $expectedLockVersion, $details): array {
            $opportunity = $this->database->fetch('SELECT * FROM opportunities WHERE id = ? AND archived_at IS NULL FOR UPDATE', $opportunityId);
            if (!$opportunity instanceof Row) {
                throw new \InvalidArgumentException('Příležitost nebyla nalezena.');
            }
            if ((int) $opportunity['lock_version'] !== $expectedLockVersion) {
                throw new OpportunityConflictException('Příležitost se mezitím změnila. Obnovte detail.');
            }
            if ((string) $opportunity['opportunity_type'] !== OpportunityType::OFFER) {
                throw new \InvalidArgumentException('Převést lze pouze pracovní nabídku nebo konkrétní poptávku.');
            }
            if ($this->database->fetchField('SELECT id FROM application_events WHERE opportunity_id = ? LIMIT 1', $opportunityId) !== null
                || $this->database->fetchField("SELECT user_id FROM user_opportunity_state WHERE opportunity_id = ? AND workflow_status <> 'none' LIMIT 1", $opportunityId) !== null) {
                throw new \InvalidArgumentException('Příležitost má historii pracovního application workflow a na firemní lead ji nelze převést.');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $newLockVersion = $expectedLockVersion + 1;
            $this->database->query('UPDATE opportunities SET', [
                'opportunity_type' => OpportunityType::COMPANY_LEAD,
                'lock_version' => $newLockVersion,
                'updated_at' => $now,
            ], 'WHERE id = ?', $opportunityId);
            $this->database->query('INSERT INTO company_lead_details', [
                'opportunity_id' => $opportunityId,
                'contact_name' => self::nullable($details?->contactName),
                'contact_role' => self::nullable($details?->contactRole),
                'channel' => self::nullable($details?->channel),
                'profile_url' => self::nullable($details?->profileUrl),
                'outreach_context' => self::nullable($details?->context),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->database->query('INSERT INTO opportunity_type_history', [
                'opportunity_id' => $opportunityId,
                'previous_type' => OpportunityType::OFFER,
                'new_type' => OpportunityType::COMPANY_LEAD,
                'previous_lock_version' => $expectedLockVersion,
                'new_lock_version' => $newLockVersion,
                'actor_user_id' => $userId,
                'created_at' => $now,
            ]);
            $this->audit->record('opportunity.type_changed', $userId, [
                'opportunity_id' => $opportunityId,
                'previous_type' => OpportunityType::OFFER,
                'new_type' => OpportunityType::COMPANY_LEAD,
                'lock_version' => $newLockVersion,
            ]);
            return ['opportunity_id' => $opportunityId, 'opportunity_type' => OpportunityType::COMPANY_LEAD, 'lock_version' => $newLockVersion, 'changed' => true];
        });
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }
}
