<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

use WooNinja\KajabiSaloon\DataTransferObjects\Lessons\Lesson;
use WooNinja\KajabiSaloon\Webhooks\WebhookDelivery;

/**
 * lesson_completed: a member marked a lesson complete
 *
 * Fires again on a re-mark (with the new time); unmarking sends nothing.
 */
final class LessonCompleted extends CourseActivityEvent
{
    public readonly Lesson $lesson;

    public function __construct(WebhookDelivery $delivery)
    {
        parent::__construct($delivery);

        $this->lesson = Lesson::fromKajabiLesson($delivery->requireResource('lessons'));
    }

    protected static function metaTimeKey(): string
    {
        return 'completed_at';
    }

    public function lessonId(): int
    {
        return $this->lesson->id;
    }

    public function contentKey(): string
    {
        return self::key($this->eventName(), (string) $this->customerId(), (string) $this->lessonId(), $this->rawOccurredAt());
    }
}
