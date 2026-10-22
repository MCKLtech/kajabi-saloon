<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Assessments;

/**
 * Assessment as sent in the assessment_completed webhook (type "assessments")
 */
final class Assessment
{
    public function __construct(
        public int    $id,
        public string $title,
    )
    {
    }

    public static function fromKajabiAssessment(array $assessment): self
    {
        return new self(
            id: (int) $assessment['id'],
            title: $assessment['attributes']['title'] ?? '',
        );
    }
}
