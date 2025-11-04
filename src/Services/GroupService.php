<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;

/**
 * Group Service - Compatibility Stub
 *
 * Kajabi does not have "Groups" in the same way Thinkific does.
 * Kajabi uses "Segments" and "Tags" for user organization.
 */
class GroupService extends Resource
{
    /**
     * Get a Group by its ID
     *
     * @param int $group_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $group_id)
    {
        throw new \Exception('Groups are not supported in Kajabi. Use Tags or Segments instead.');
    }

    /**
     * Get a list of Groups
     *
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function groups(array $filters = []): Paginator
    {
        throw new \Exception('Groups are not supported in Kajabi. Use Tags or Segments instead.');
    }

    /**
     * Get Users in a Group
     *
     * @param int $group_id
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function users(int $group_id, array $filters = []): Paginator
    {
        throw new \Exception('Groups are not supported in Kajabi. Use Tags or Segments instead.');
    }
}
