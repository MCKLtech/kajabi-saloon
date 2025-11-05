<?php

namespace WooNinja\KajabiSaloon\Requests\Customers;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Customers\Customer;

class GetCustomers extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function __construct(
        private array $filters = [],
        private ?string $defaultSiteId = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/customers';
    }

    protected function defaultQuery(): array
    {
        $query = [];
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'limit': $query['page[size]'] = $value; break;
                case 'page': $query['page[number]'] = $value; break;
                case 'site_id': $query['filter[site_id]'] = $value; break;
                case 'search': $query['filter[search]'] = $value; break;
                // Skip pagination control parameters (handled by paginator, not API)
                case 'start_page':
                case 'max_pages':
                    break;
                default: $query[$key] = $value; break;
            }
        }
        if (!isset($query['filter[site_id]']) && $this->defaultSiteId) {
            $query['filter[site_id]'] = $this->defaultSiteId;
        }
        return $query;
    }

    public function createDtoFromResponse($response): array
    {
        $data = $response->json('data', []);
        return array_map(fn($customer) => Customer::fromKajabiCustomer($customer), $data);
    }
}
