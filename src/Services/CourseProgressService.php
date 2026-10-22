<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\Exceptions\FeatureNotEnabledException;
use WooNinja\KajabiSaloon\Requests\CourseProgress\GetCourseProgress;
use WooNinja\KajabiSaloon\Sync\CourseProgressSync;

/**
 * Course progress (GET /v1/course_progress)
 *
 * Every member's progress in one course, with per-lesson detail. Use it to
 * backfill and to catch webhooks that were never delivered. Throws
 * FeatureNotEnabledException on a 423.
 *
 * Course ids are Kajabi product ids. Cohort courses return 404.
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
