<?php

namespace WooNinja\KajabiSaloon\Requests\Purchases;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;

class GetPurchases extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function __construct(
        private array $filters = [],
        private ?string $defaultSiteId = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/purchases';
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Map Thinkific-style filters to Kajabi filters
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'site_id':
                    $query['filter[site_id]'] = $value;
                    break;
                // Thinkific-style query filters
                case 'query[user_id]':
                case 'user_id':
                    $query['filter[customer_id]'] = $value;
                    break;
                case 'query[course_id]':
                case 'course_id':
                    $query['filter[product_id]'] = $value;
                    break;
                case 'query[email]':
                    $query['filter[customer_email]'] = $value;
                    break;
                // Date filters
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

        // Include offer and customer relationships to get course and user data
        // This avoids N+1 queries by fetching related data in the same request
        if (!isset($query['include'])) {
            $query['include'] = 'offer,customer';
        }

        return $query;
    }

    public function createDtoFromResponse($response): array
    {
        $data = $response->json('data', []);
        $included = $response->json('included', []);

        // Create a lookup map for included resources (offers)
        $includedMap = [];
        foreach ($included as $resource) {
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            if ($type && $id) {
                $includedMap["{$type}:{$id}"] = $resource;
            }
        }

        return array_map(function ($purchase) use ($includedMap) {
            return Enrollment::fromKajabiPurchase($purchase, $includedMap);
        }, $data);
    }
}
