<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;

/**
 * Site Script Service - Compatibility Stub
 *
 * Kajabi does not have a Site Scripts API.
 * Custom scripts are managed through the Kajabi admin interface.
 */
class SiteScriptService extends Resource
{
    /**
     * Get a Site Script by ID
     *
     * @param int $site_script_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $site_script_id)
    {
        throw new \Exception('Site Scripts are not available in Kajabi API. Manage scripts through the Kajabi admin interface.');
    }

    /**
     * Get a list of Site Scripts
     *
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function siteScripts(array $filters = []): Paginator
    {
        throw new \Exception('Site Scripts are not available in Kajabi API. Manage scripts through the Kajabi admin interface.');
    }

    /**
     * Create a Site Script
     *
     * @param mixed $siteScript
     * @return mixed
     * @throws \Exception
     */
    public function create($siteScript)
    {
        throw new \Exception('Site Scripts are not available in Kajabi API. Manage scripts through the Kajabi admin interface.');
    }

    /**
     * Update a Site Script
     *
     * @param mixed $siteScript
     * @return Response
     * @throws \Exception
     */
    public function update($siteScript): Response
    {
        throw new \Exception('Site Scripts are not available in Kajabi API. Manage scripts through the Kajabi admin interface.');
    }

    /**
     * Delete a Site Script
     *
     * @param int $site_script_id
     * @return void
     * @throws \Exception
     */
    public function delete(int $site_script_id): void
    {
        throw new \Exception('Site Scripts are not available in Kajabi API. Manage scripts through the Kajabi admin interface.');
    }
}
