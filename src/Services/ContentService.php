<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;

/**
 * Content Service - Compatibility Stub
 *
 * Kajabi uses "Lessons" instead of Thinkific's "Contents".
 */
class ContentService extends Resource
{
    /**
     * Get Content by ID
     *
     * @param int $content_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $content_id)
    {
        throw new \Exception('Contents are not directly supported in Kajabi, which has lessons but no lesson endpoints. Use $kajabi->courses->lessons($productId) to read the lessons of a course.');
    }

    /**
     * Get a list of Contents
     *
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function contents(array $filters = []): Paginator
    {
        throw new \Exception('Contents are not directly supported in Kajabi, which has lessons but no lesson endpoints. Use $kajabi->courses->lessons($productId) to read the lessons of a course.');
    }
}
