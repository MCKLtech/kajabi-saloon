<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;

/**
 * Course Review Service - Compatibility Stub
 *
 * Kajabi does not have a public API for course reviews.
 */
class CourseReviewService extends Resource
{
    /**
     * Get a Course Review by its ID
     *
     * @param int $review_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $review_id)
    {
        throw new \Exception('Course reviews are not available in Kajabi API.');
    }

    /**
     * Get Course Reviews
     *
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function reviews(array $filters = []): Paginator
    {
        throw new \Exception('Course reviews are not available in Kajabi API.');
    }

    /**
     * Create a Course Review
     *
     * @param mixed $review
     * @return mixed
     * @throws \Exception
     */
    public function create($review)
    {
        throw new \Exception('Course reviews are not available in Kajabi API.');
    }
}
