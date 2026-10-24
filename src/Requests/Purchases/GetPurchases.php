<?php

namespace WooNinja\KajabiSaloon\Requests\Purchases;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

class GetPurchases extends Request implements Paginatable, DeclaresSupportedFilters
{
    use JsonApiQuery;

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
                case 'limit':
                    $query['page[size]'] = $value;
                    break;
                case 'page':
                    $query['page[number]'] = $value;
                    break;
                // Paging controls: read (and stripped) by KajabiConnector::paginate()
                case 'start_page':
                case 'max_pages':
                    $query[$key] = $value;
                    break;
                // Thinkific-style query filters
                case 'query[user_id]':
                case 'user_id':
                    $query['filter[customer_id]'] = $value;
                    break;
                // Unsupported by the Kajabi /v1/purchases endpoint — silently dropped.
                // (query[email] is resolved to filter[customer_id] by
                // EnrollmentService::enrollments before this request is built;
                // course_id has no supported filter on /v1/purchases.)
                case 'query[course_id]':
                case 'course_id':
                case 'query[email]':
                case 'email':
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

    public static function supportedFilters(): array
    {
        return SupportedFilters::PURCHASES;
    }
}
