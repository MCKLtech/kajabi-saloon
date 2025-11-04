<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;

/**
 * Custom Profile Field Definition Service
 *
 * Kajabi has "Custom Fields" which are similar to Thinkific's Custom Profile Fields.
 * API Endpoint: /v1/custom_fields
 */
class CustomProfileFieldDefinitionService extends Resource
{
    /**
     * Get Custom Profile Field Definitions
     *
     * Note: Kajabi API endpoint is /v1/custom_fields
     *
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function customProfileFieldDefinitions(array $filters = []): Paginator
    {
        throw new \Exception('Custom Profile Field Definitions API not yet implemented for Kajabi. Use /v1/custom_fields endpoint directly.');
    }
}
