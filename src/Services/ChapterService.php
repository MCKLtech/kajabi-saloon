<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;

/**
 * Chapter Service - Compatibility Stub
 *
 * Kajabi uses "Modules" and "Lessons" instead of Thinkific's "Chapters" and "Contents".
 * This service provides compatibility but the underlying API structure is different.
 */
class ChapterService extends Resource
{
    /**
     * Get a Course Chapter by its ID
     *
     * @param int $chapter_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $chapter_id)
    {
        throw new \Exception('Chapters are not directly supported in Kajabi, which has modules and lessons but no endpoints for them. Use $kajabi->courses->structure($productId) (or ->modules() / ->lessons()) to read the modules and lessons of a course.');
    }

    /**
     * Get a list of chapter contents
     *
     * @param int $chapter_id
     * @return Paginator
     * @throws \Exception
     */
    public function content(int $chapter_id): Paginator
    {
        throw new \Exception('Chapters are not directly supported in Kajabi, which has modules and lessons but no endpoints for them. Use $kajabi->courses->structure($productId) (or ->modules() / ->lessons()) to read the modules and lessons of a course.');
    }
}
