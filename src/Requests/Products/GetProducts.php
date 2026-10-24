<?php

namespace WooNinja\KajabiSaloon\Requests\Products;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Products\Product;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

class GetProducts extends Request implements Paginatable, DeclaresSupportedFilters
{
    use JsonApiQuery;

    /**
     * productizable type of cohort courses (per the spec), for exclude_product_types
     */
    public const COHORT_COURSE = 'Courses::CohortCourse';

    protected Method $method = Method::GET;

    public function __construct(
        private array $filters = [],
        private ?string $defaultSiteId = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/products';
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
                case 'name_cont':
                    // Kajabi products filter on title (not name)
                    $query['filter[title_cont]'] = $value;
                    break;
                case 'description_cont':
                    $query['filter[description_cont]'] = $value;
                    break;
                case 'exclude_product_types':
                    // Fully-qualified productizable types, e.g. self::COHORT_COURSE
                    $types = array_values((array) $value);
                    if (count($types) === 1) {
                        $query['filter[exclude_product_types][]'] = $types[0];
                    } elseif ($types !== []) {
                        $query['filter[exclude_product_types]'] = $types;
                    }
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
        
        return array_map(function ($product) {
            return Product::fromKajabiProduct($product);
        }, $data);
    }

    public static function supportedFilters(): array
    {
        return SupportedFilters::PRODUCTS;
    }
}
