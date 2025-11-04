<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;

class GetContacts extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function __construct(
        private array $filters = [],
        private ?string $defaultSiteId = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/contacts';
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Map Thinkific-style filters to Kajabi filters
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'query[email]':
                    // Map to Kajabi's search parameter
                    $query['filter[search]'] = $value;
                    break;
                case 'limit':
                    $query['page[size]'] = $value;
                    break;
                case 'page':
                    $query['page[number]'] = $value;
                    break;
                case 'site_id':
                    $query['filter[site_id]'] = $value;
                    break;
                default:
                    // Pass through other filters as-is for now
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
        
        return array_map(function ($contact) {
            return User::fromKajabiContact($contact);
        }, $data);
    }
}
