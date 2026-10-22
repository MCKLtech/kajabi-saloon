<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress;

use Carbon\Carbon;

/**
 * One member's progress in one course, from GET /v1/course_progress
 *
 * Store rows keyed on $id (the progress row id, also the paging cursor).
 */
final class CourseProgress
{
    /**
     * @param LessonProgress[] $lessons
     */
    public function __construct(
        public int     $id,
        public int     $customer_id,
        public ?int    $product_id,
        public int     $completed_lessons,
        public int     $total_lessons,
        public int     $percent_complete,   // rounded whole number, 0-100
        public bool    $completed,
        public ?Carbon $updated_at,
        public array   $lessons,
    )
    {
    }

    /**
     * @param array $row course_progress resource
     * @param int|null $productId The course queried (not part of the row itself)
     */
    public static function fromKajabiCourseProgress(array $row, ?int $productId = null): self
    {
        $attributes = $row['attributes'] ?? [];

        return new self(
            id: (int) $row['id'],
            customer_id: (int) ($attributes['customer_id'] ?? 0),
            product_id: $productId,
            completed_lessons: (int) ($attributes['completed_lessons'] ?? 0),
            total_lessons: (int) ($attributes['total_lessons'] ?? 0),
            percent_complete: (int) ($attributes['percent_complete'] ?? 0),
            completed: (bool) ($attributes['completed'] ?? false),
            updated_at: isset($attributes['updated_at']) ? Carbon::parse($attributes['updated_at']) : null,
            lessons: array_map(
                fn(array $lesson) => LessonProgress::fromKajabiLessonProgress($lesson),
                $attributes['lessons'] ?? []
            ),
        );
    }

    /**
     * Earliest lesson completion, or null when no lesson is complete
     */
    public function startedAt(): ?Carbon
    {
        $times = $this->completionTimes();

        return $times === [] ? null : min($times);
    }

    /**
     * Latest lesson completion when the course is complete, otherwise null
     */
    public function completedAt(): ?Carbon
    {
        if (!$this->completed) {
            return null;
        }

        $times = $this->completionTimes();

        return $times === [] ? null : max($times);
    }

    /**
     * @return Carbon[]
     */
    private function completionTimes(): array
    {
        return array_values(array_filter(array_map(
            fn(LessonProgress $lesson) => $lesson->completed_at,
            $this->lessons
        )));
    }
}
