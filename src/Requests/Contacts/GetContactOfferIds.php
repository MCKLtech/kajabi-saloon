<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Ids of the offers a Contact holds
 *
 * Endpoint: GET /contacts/{contact_id}?include=offers (the path GetContactOffers uses)
 */
class GetContactOfferIds extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private int $contactId)
    {
    }

    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contactId}";
    }

    protected function defaultQuery(): array
    {
        return ['include' => 'offers'];
    }

    /**
     * @return int[]
     */
    public function createDtoFromResponse($response): array
    {
        return array_map(
            fn(array $identifier) => (int) $identifier['id'],
            $response->json('data.relationships.offers.data', [])
        );
    }
}
