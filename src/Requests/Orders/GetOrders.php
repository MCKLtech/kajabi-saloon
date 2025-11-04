<?php

namespace WooNinja\KajabiSaloon\Requests\Orders;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Orders\Order;

class GetOrders extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function __construct(
        private array $filters = [],
        private ?string $defaultSiteId = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/orders';
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Map Thinkific-style filters to Kajabi filters
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'limit':
                    $query['page[size]'] = $value;
                    break;
                case 'page':
                    $query['page[number]'] = $value;
                    break;
                case 'site_id':
                    $query['filter[site_id]'] = $value;
                    break;
                case 'user_id':
                    $query['filter[customer_id]'] = $value;
                    break;
                case 'created_at_gte':
                    $query['filter[created_at_gte]'] = $value;
                    break;
                case 'created_at_lte':
                    $query['filter[created_at_lte]'] = $value;
                    break;
                default:
                    // Pass through other filters as-is
                    $query[$key] = $value;
                    break;
            }
        }

        // If no site_id was provided in filters but we have a default, use it
        if (!isset($query['filter[site_id]']) && $this->defaultSiteId) {
            $query['filter[site_id]'] = $this->defaultSiteId;
        }

        return $query;
    }

    public function createDtoFromResponse($response): array
    {
        $data = $response->json('data', []);
        
        return array_map(function ($order) {
            return Order::fromKajabiOrder($order);
        }, $data);
    }
}
