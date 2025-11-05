<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\CustomProfileFieldDefinitions\CustomProfileFieldDefinition;
use WooNinja\KajabiSaloon\Requests\CustomFields\GetCustomFields;

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
     * Fetches custom fields from Kajabi API and transforms them to Thinkific format.
     * Maps Kajabi's /v1/custom_fields to Thinkific's custom profile field definitions.
     *
     * @param array $filters Optional filters: site_id, limit, page
     * @return Paginator<CustomProfileFieldDefinition>
     */
    public function definitions(array $filters = []): Paginator
    {
        return $this->connector->paginate(
            new GetCustomFields($filters, $this->getDefaultSiteId())
        );
    }
}
