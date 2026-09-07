<?php

declare(strict_types=1);

namespace App\Opportunity;

final readonly class OpportunityImportResult
{
    public function __construct(
        public int $opportunityId,
        public int $sourceVersionId,
        public bool $opportunityCreated,
        public bool $versionCreated,
    ) {
    }
}
