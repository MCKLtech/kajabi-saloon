<?php

namespace Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;

class WebhookEventTest extends TestCase
{
    public function test_has_all_eleven_events(): void
    {
        $this->assertSame([
            'purchase', 'payment_succeeded', 'order_created', 'form_submission', 'tag_added', 'tag_removed',
            'lesson_completed', 'course_started', 'course_completed', 'assessment_completed', 'member_signed_in',
        ], array_map(fn(WebhookEvent $e) => $e->value, WebhookEvent::cases()));
    }

    public function test_only_lesson_and_course_events_are_course_scopable(): void
    {
        $scopable = array_values(array_filter(WebhookEvent::cases(), fn(WebhookEvent $e) => $e->isCourseScopable()));

        $this->assertSame(
            [WebhookEvent::LessonCompleted, WebhookEvent::CourseStarted, WebhookEvent::CourseCompleted],
            $scopable
        );
    }

    public function test_member_activity_events(): void
    {
        $this->assertTrue(WebhookEvent::AssessmentCompleted->isMemberActivity());
        $this->assertTrue(WebhookEvent::MemberSignedIn->isMemberActivity());
        $this->assertFalse(WebhookEvent::Purchase->isMemberActivity());
    }

    public function test_only_member_signed_in_is_best_effort(): void
    {
        foreach (WebhookEvent::cases() as $event) {
            $this->assertSame($event === WebhookEvent::MemberSignedIn, $event->isBestEffort(), $event->value);
        }
    }

    public function test_sample_endpoint(): void
    {
        $this->assertSame('/hooks/lesson_completed_sample', WebhookEvent::LessonCompleted->sampleEndpoint());
        $this->assertSame('/hooks/purchase_sample', WebhookEvent::Purchase->sampleEndpoint());
    }

    public function test_from_mixed_accepts_enum_or_string(): void
    {
        $this->assertSame(WebhookEvent::CourseStarted, WebhookEvent::fromMixed('course_started'));
        $this->assertSame(WebhookEvent::CourseStarted, WebhookEvent::fromMixed(WebhookEvent::CourseStarted));

        $this->expectException(\ValueError::class);
        WebhookEvent::fromMixed('not_an_event');
    }
}
