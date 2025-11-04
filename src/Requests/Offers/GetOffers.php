<?php

namespace WooNinja\KajabiSaloon\Requests\Offers;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Offers\Offer;

class GetOffers extends Request implements Paginatable
{
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
}
