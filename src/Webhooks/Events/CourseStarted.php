<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

/**
 * course_started: a lesson mark left the member with exactly one completed lesson
 *
 * Can fire more than once per member and course; the same mark also sends lesson_completed.
 */
final class CourseStarted extends CourseActivityEvent
{
    protected static function metaTimeKey(): string
    {
        return 'started_at';
    }
}
