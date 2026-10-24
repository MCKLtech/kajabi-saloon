<?php

namespace WooNinja\KajabiSaloon\Requests\Orders;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Orders\Order;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

class GetOrders extends Request implements Paginatable, DeclaresSupportedFilters
{
    use JsonApiQuery;

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
                // Date-range filters are not supported by GET /v1/orders — silently dropped
                case 'created_at_gte':
                case 'created_at_lte':
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

        // Items and the customer's email/name are only in `included`
        if (!isset($query['include'])) {
            $query['include'] = 'order_items,customer';
        }

        return $query;
    }

    public function createDtoFromResponse($response): array
    {
        $included = Order::includedMap($response->json('included', []));

        return array_map(
            fn(array $order) => Order::fromKajabiOrder($order, $included),
            $response->json('data', [])
        );
    }

    public static function supportedFilters(): array
    {
        return SupportedFilters::ORDERS;
    }
}
