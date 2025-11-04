<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;

/**
 * Bundle Service - Compatibility Stub
 *
 * Kajabi does not have a direct equivalent to Thinkific's Bundles.
 * In Kajabi, you would use Offers and Products to achieve similar functionality.
 */
class BundleService extends Resource
{
    /**
     * Get a Bundle by its ID
     *
     * @param int $productable_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $productable_id)
    {
        throw new \Exception('Bundles are not supported in Kajabi. Use Offers and Products instead.');
    }

    /**
     * Get the Courses of a Bundle
     *
     * @param int $productable_id
     * @return Paginator
     * @throws \Exception
     */
    public function courses(int $productable_id): Paginator
    {
        throw new \Exception('Bundles are not supported in Kajabi. Use Offers and Products instead.');
    }

    /**
     * Get the enrollments of a Bundle
     *
     * @param int $productable_id
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function enrollments(int $productable_id, array $filters = []): Paginator
    {
        throw new \Exception('Bundles are not supported in Kajabi. Use Offers and Products instead.');
    }
}
