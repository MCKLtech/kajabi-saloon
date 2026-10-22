<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress;

use Carbon\Carbon;

/**
 * One published lesson inside a course_progress row
 *
 * Only lessons marked complete count toward completion; progress_percent is
 * video progress and does not.
 */
final class LessonProgress
{
    public function __construct(
        public int     $lesson_id,
        public ?Carbon $completed_at,
        public ?int    $progress_percent,
    )
    {
    }

    public static function fromKajabiLessonProgress(array $lesson): self
    {
        return new self(
            lesson_id: (int) $lesson['lesson_id'],
            completed_at: isset($lesson['completed_at']) ? Carbon::parse($lesson['completed_at']) : null,
            progress_percent: isset($lesson['progress_percent']) ? (int) $lesson['progress_percent'] : null,
        );
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
