<?php

declare(strict_types=1);

namespace App\Opportunity;

final class OpportunityType
{
    public const OFFER = 'offer';
    public const COMPANY_LEAD = 'company_lead';
    public const TENDER = 'tender';

    public const LABELS = [
        self::OFFER => 'Pracovní nabídka / poptávka',
        self::COMPANY_LEAD => 'Firemní kontakt / lead',
        self::TENDER => 'Výběrové řízení / tender',
    ];

    public static function validate(string $type): string
    {
        if (!isset(self::LABELS[$type])) {
            throw new \InvalidArgumentException('Typ příležitosti není platný.');
        }
        return $type;
    }
}
