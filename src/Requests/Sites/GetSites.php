<?php

namespace WooNinja\KajabiSaloon\Requests\Sites;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Sites\Site;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

class GetSites extends Request implements Paginatable, DeclaresSupportedFilters
{
    use JsonApiQuery;

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
                // Paging controls: read (and stripped) by KajabiConnector::paginate()
                case 'start_page':
                case 'max_pages':
                    $query[$key] = $value;
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

    public static function supportedFilters(): array
    {
        return SupportedFilters::SITES;
    }
}
