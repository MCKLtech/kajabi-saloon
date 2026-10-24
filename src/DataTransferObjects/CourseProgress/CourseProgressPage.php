<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress;

/**
 * One page of GET /v1/course_progress
 *
 * Pass $nextCursor back as `after` to fetch the next page; null means this was
 * the last one. Cursors are row ids (rows come back ordered by id), so a
 * stored cursor stays valid between processes.
 */
final class CourseProgressPage
{
    /**
     * @param CourseProgress[] $rows
     */
    public function __construct(
        public readonly int     $product_id,
        public readonly array   $rows,
        public readonly ?string $nextCursor,
    )
    {
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
