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
        throw new \Exception('Chapters are not directly supported in Kajabi. Kajabi uses Modules and Lessons. Check the Kajabi API documentation for module/lesson endpoints.');
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
        throw new \Exception('Chapters are not directly supported in Kajabi. Kajabi uses Modules and Lessons. Check the Kajabi API documentation for module/lesson endpoints.');
    }
}
