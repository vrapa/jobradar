<?php

declare(strict_types=1);

namespace App\Assessment;

enum AssessmentRecommendation: string
{
    case React = 'react';
    case Uninteresting = 'uninteresting';
    case Verify = 'verify';
}
