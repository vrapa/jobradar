<?php

declare(strict_types=1);

namespace App\Decision;

enum OpportunityDecision: string
{
    case Undecided = 'undecided';
    case React = 'react';
    case Uninteresting = 'uninteresting';
}
