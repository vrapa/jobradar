<?php

declare(strict_types=1);

namespace App\Assessment;

final readonly class AssessmentConfigurationResult
{
    public function __construct(public int $profileId, public int $ruleSetId)
    {
    }
}
