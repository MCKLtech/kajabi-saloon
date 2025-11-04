<?php

namespace WooNinja\KajabiSaloon\Requests\Webhooks;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Webhooks\Webhook;

class GetWebhooks extends Request implements Paginatable
{
    protected Method $method = Method::GET;
    
    public function __construct(private array $filters = []) {}
    
    public function resolveEndpoint(): string { return '/hooks'; } // Kajabi uses /hooks
    
    protected function defaultQuery(): array
    {
        $query = [];
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'limit': $query['page[size]'] = $value; break;
                case 'page': $query['page[number]'] = $value; break;
                default: $query[$key] = $value; break;
            }
        }
        return $query;
    }
    
    public function createDtoFromResponse($response): array
    {
        return array_map(fn($hook) => Webhook::fromKajabiWebhook($hook), $response->json('data', []));
    }
}
