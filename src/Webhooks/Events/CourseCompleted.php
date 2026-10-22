<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

/**
 * course_completed: a lesson mark left every published lesson in the course complete
 *
 * Fires on every later mark while the course stays complete. Handle idempotently.
 */
final class CourseCompleted extends CourseActivityEvent
{
    protected static function metaTimeKey(): string
    {
        return 'completed_at';
    }
}
