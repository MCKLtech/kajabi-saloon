<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\Webhooks\WebhookDelivery;

/**
 * Member event about a course ("customers" + "courses" resources)
 *
 * The course id is the Kajabi product id, so it can be passed straight to
 * $kajabi->courseProgress. For cohorts it is the cohort's own product, which
 * the course progress endpoint doesn't support (webhooks are the only source).
 */
abstract class CourseActivityEvent extends CustomerActivityEvent
{
    public readonly Course $course;

    public function __construct(WebhookDelivery $delivery)
    {
        parent::__construct($delivery);

        $this->course = Course::fromKajabiCourse($delivery->requireResource('courses'));
    }

    public function courseId(): int
    {
        return $this->course->id;
    }

    /**
     * Lesson ids listed on the course resource
     *
     * @return int[]
     */
    public function courseLessonIds(): array
    {
        $lessons = $this->delivery->requireResource('courses')['relationships']['lessons']['data'] ?? [];

        return array_map(fn(array $lesson) => (int) $lesson['id'], $lessons);
    }

    public function contentKey(): string
    {
        return self::key($this->eventName(), (string) $this->customerId(), (string) $this->courseId(), $this->rawOccurredAt());
    }
}
