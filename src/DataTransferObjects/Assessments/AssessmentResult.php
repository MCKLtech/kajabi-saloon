<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Assessments;

use Carbon\Carbon;

/**
 * Assessment result from the assessment_completed webhook (type "assessment_results")
 *
 * passed/grade are null for a manually graded assessment. Grading it later
 * sends no further event. The payload never contains answers or question text.
 */
final class AssessmentResult
{
    public function __construct(
        public int     $id,
        public ?bool   $passed,
        public ?int    $grade,        // whole-number percentage, 0-100
        public ?Carbon $submitted_at,
    )
    {
    }

    public static function fromKajabiAssessmentResult(array $result): self
    {
        $attributes = $result['attributes'] ?? [];

        return new self(
            id: (int) $result['id'],
            passed: isset($attributes['passed']) ? (bool) $attributes['passed'] : null,
            grade: isset($attributes['grade']) ? (int) $attributes['grade'] : null,
            submitted_at: isset($attributes['submitted_at']) ? Carbon::parse($attributes['submitted_at']) : null,
        );
    }

    /**
     * False for a manually graded result that hasn't been graded yet
     */
    public function isGraded(): bool
    {
        return $this->passed !== null;
    }
}
