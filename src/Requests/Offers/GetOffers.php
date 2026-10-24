<?php

namespace WooNinja\KajabiSaloon\Requests\Offers;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Offers\Offer;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

class GetOffers extends Request implements Paginatable, DeclaresSupportedFilters
{
    use JsonApiQuery;

    protected Method $method = Method::GET;
    
    public function __construct(private array $filters = [], private ?string $defaultSiteId = null) {}
    
    public function resolveEndpoint(): string { return '/offers'; }
    
    protected function defaultQuery(): array
    {
        $query = [];
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'limit': $query['page[size]'] = $value; break;
                case 'page': $query['page[number]'] = $value; break;
                case 'site_id': $query['filter[site_id]'] = $value; break;
                // Paging controls: read (and stripped) by KajabiConnector::paginate()
                case 'start_page':
                case 'max_pages':
                    $query[$key] = $value;
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
        return array_map(fn($offer) => Offer::fromKajabiOffer($offer), $response->json('data', []));
    }

    public static function supportedFilters(): array
    {
        return SupportedFilters::OFFERS;
    }
}
