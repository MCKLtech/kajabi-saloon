<?php

namespace WooNinja\KajabiSaloon\Requests\Webhooks;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Webhooks\Webhook;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;

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
                case 'event': $query['filter[event_eq]'] = $value instanceof WebhookEvent ? $value->value : $value; break;
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
        return array_map(fn($hook) => Webhook::fromKajabiWebhook($hook), $response->json('data', []));
    }
}
