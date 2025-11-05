<?php

declare(strict_types=1);

namespace WooNinja\KajabiSaloon\Requests\CustomFields;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\CustomProfileFieldDefinitions\CustomProfileFieldDefinition;

class GetCustomFields extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function __construct(
        protected array $filters = [],
        protected ?string $defaultSiteId = null
    ) {
    }

    public function resolveEndpoint(): string
    {
        return '/custom_fields';
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Add site_id filter if available
        if (isset($this->filters['site_id'])) {
            $query['filter[site_id]'] = $this->filters['site_id'];
        } elseif ($this->defaultSiteId) {
            $query['filter[site_id]'] = $this->defaultSiteId;
        }

        // Add pagination if provided
        if (isset($this->filters['limit'])) {
            $query['page[size]'] = $this->filters['limit'];
        }

        if (isset($this->filters['page'])) {
            $query['page[number]'] = $this->filters['page'];
        }

        return $query;
    }

    /**
     * Transform Kajabi custom fields response to CustomProfileFieldDefinition DTOs
     *
     * @param mixed $response
     * @return array<CustomProfileFieldDefinition>
     */
    public function createDtoFromResponse($response): array
    {
        $data = $response->json('data', []);

        return array_map(function ($customField) {
            return CustomProfileFieldDefinition::fromKajabiCustomField($customField);
        }, $data);
    }
}