<?php

namespace WooNinja\KajabiSaloon\Requests\Sites;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Sites\Site;

class GetSites extends Request implements Paginatable
{
    protected Method $method = Method::GET;
    
    public function __construct(private array $filters = []) {}
    
    public function resolveEndpoint(): string { return '/sites'; }
    
    protected function defaultQuery(): array
    {
        $query = [];
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'limit': $query['page[size]'] = $value; break;
                case 'page': $query['page[number]'] = $value; break;
                // Skip pagination control parameters (handled by paginator, not API)
                case 'start_page':
                case 'max_pages':
                    break;
                default: $query[$key] = $value; break;
            }
        }
        return $query;
    }
    
    public function createDtoFromResponse($response): array
    {
        return array_map(fn($site) => Site::fromKajabiSite($site), $response->json('data', []));
    }
}
