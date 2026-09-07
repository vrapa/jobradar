<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Assessment\AssessmentInput;
use App\Assessment\AssessmentRecommendation;
use PHPUnit\Framework\TestCase;

final class AssessmentInputTest extends TestCase
{
    public function testAllowsQualitativeAssessmentWithoutScore(): void
    {
        $input = new AssessmentInput(
            1,
            1,
            AssessmentRecommendation::Verify,
            '0.500',
            '0.600',
            'Je nutné ověřit sazbu.',
            authorType: 'system',
        );

        self::assertFalse($input->hasNumericScore());
        self::assertSame(AssessmentRecommendation::Verify, $input->recommendation);
    }

    public function testRejectsPartialScoreRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AssessmentInput(1, 1, AssessmentRecommendation::React, '1', '0.8', 'Shrnutí', scoreMin: '10', authorType: 'system');
    }

    public function testAssistantMustIdentifyModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AssessmentInput(1, 1, AssessmentRecommendation::Verify, '0', '0', 'Shrnutí', authorType: 'assistant');
    }
}
