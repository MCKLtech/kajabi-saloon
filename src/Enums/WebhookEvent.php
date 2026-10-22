<?php

namespace WooNinja\KajabiSaloon\Enums;

/**
 * Kajabi webhook (hook) event names
 */
enum WebhookEvent: string
{
    case Purchase = 'purchase';
    case PaymentSucceeded = 'payment_succeeded';
    case OrderCreated = 'order_created';
    case FormSubmission = 'form_submission';
    case TagAdded = 'tag_added';
    case TagRemoved = 'tag_removed';

    // Member activity events
    case LessonCompleted = 'lesson_completed';
    case CourseStarted = 'course_started';
    case CourseCompleted = 'course_completed';
    case AssessmentCompleted = 'assessment_completed';
    case MemberSignedIn = 'member_signed_in';

    /**
     * Whether a hook for this event can be limited to one course via resource_id
     */
    public function isCourseScopable(): bool
    {
        return in_array($this, [self::LessonCompleted, self::CourseStarted, self::CourseCompleted], true);
    }

    /**
     * Whether this is one of the member activity events
     */
    public function isMemberActivity(): bool
    {
        return in_array($this, [
            self::LessonCompleted,
            self::CourseStarted,
            self::CourseCompleted,
            self::AssessmentCompleted,
            self::MemberSignedIn,
        ], true);
    }

    /**
     * Delivery is best effort: Kajabi may drop the event and never retry it
     */
    public function isBestEffort(): bool
    {
        return $this === self::MemberSignedIn;
    }

    /**
     * Endpoint returning a sample payload for this event, e.g. /hooks/lesson_completed_sample
     */
    public function sampleEndpoint(): string
    {
        return "/hooks/{$this->value}_sample";
    }

    public static function fromMixed(self|string $event): self
    {
        return $event instanceof self ? $event : self::from($event);
    }
}
