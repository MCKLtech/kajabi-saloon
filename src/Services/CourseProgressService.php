<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgressPage;
use WooNinja\KajabiSaloon\Exceptions\CourseProgressForbiddenException;
use WooNinja\KajabiSaloon\Exceptions\CourseProgressNotFoundException;
use WooNinja\KajabiSaloon\Exceptions\FeatureNotEnabledException;
use WooNinja\KajabiSaloon\Requests\CourseProgress\GetCourseProgress;
use WooNinja\KajabiSaloon\Sync\CourseProgressSync;

/**
 * Course progress (GET /v1/course_progress)
 *
 * Every member's progress in one course, with per-lesson detail. Use it to
 * backfill and to catch webhooks that were never delivered.
 *
 * Errors: 423 FeatureNotEnabledException, 403 CourseProgressForbiddenException,
 * 404 CourseProgressNotFoundException (not a "Course" product - community,
 * coaching, podcast, cohort - or not on this account; permanent for that id).
 *
 * Course ids are Kajabi product ids (and equal the /v1/courses id).
 * Pages hold at most 100 rows; a larger page[size] is silently capped.
 */
class CourseProgressService extends Resource
{
    /**
     * All progress rows for a course, ordered by row id
     *
     * @param array $filters customer_id, updated_since, limit (max 100), max_pages
     */
    public function forCourse(int $productId, array $filters = []): Paginator
    {
        return $this->connector->paginate(new GetCourseProgress($productId, $filters));
    }

    /**
     * One page of rows, for walks that must stop and resume (time budgets, queued jobs)
     *
     * ```php
     * $after = $store->cursor($productId);          // null = start
     * do {
     *     $page = $kajabi->courseProgress->page($productId, ['limit' => 100], $after);
     *     foreach ($page->rows as $row) { $repo->upsert($row); }
     *     $store->saveCursor($productId, $after = $page->nextCursor);
     * } while ($page->hasMore() && !$deadlineReached());
     * ```
     *
     * @param array $filters customer_id, updated_since, limit (max 100)
     * @param string|null $after Cursor from the previous page's nextCursor; null for the first page
     * @throws FeatureNotEnabledException
     * @throws CourseProgressForbiddenException
     * @throws CourseProgressNotFoundException
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function page(int $productId, array $filters = [], ?string $after = null): CourseProgressPage
    {
        unset($filters['after'], $filters['page[after]']);

        if ($after !== null) {
            $filters['after'] = $after;
        }

        $response = $this->connector->send(new GetCourseProgress($productId, $filters));

        return new CourseProgressPage(
            product_id: $productId,
            rows: $response->dtoOrFail(),
            nextCursor: GetCourseProgress::cursorFrom($response->json('links.next')),
        );
    }

    /**
     * Rows updated at or after $since
     */
    public function updatedSince(int $productId, \DateTimeInterface|string $since, array $filters = []): Paginator
    {
        return $this->forCourse($productId, ['updated_since' => $since] + $filters);
    }

    /**
     * One member's progress in a course, or null when they have no progress record
     *
     * @throws FeatureNotEnabledException
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function forCustomer(int $productId, int $customerId): ?CourseProgress
    {
        $rows = $this->connector
            ->send(new GetCourseProgress($productId, ['customer_id' => $customerId, 'limit' => 1]))
            ->dtoOrFail();

        return $rows[0] ?? null;
    }

    /**
     * Helper implementing Kajabi's recommended incremental + full-pass sync
     */
    public function sync(int $marginSeconds = CourseProgressSync::DEFAULT_MARGIN_SECONDS): CourseProgressSync
    {
        return new CourseProgressSync($this, $marginSeconds);
    }
}
