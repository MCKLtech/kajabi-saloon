<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;

/**
 * Instructor Service - Compatibility Stub
 *
 * Kajabi does not have a separate Instructor resource in the API.
 * Instructors are managed through the Kajabi admin interface.
 */
class InstructorService extends Resource
{
    /**
     * Get an Instructor by ID
     *
     * @param int $instructor_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $instructor_id)
    {
        throw new \Exception('Instructors are not available in Kajabi API. Manage instructors through the Kajabi admin interface.');
    }

    /**
     * Get a list of Instructors
     *
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function instructors(array $filters = []): Paginator
    {
        throw new \Exception('Instructors are not available in Kajabi API. Manage instructors through the Kajabi admin interface.');
    }
}
