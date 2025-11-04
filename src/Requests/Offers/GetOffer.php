<?php

namespace WooNinja\KajabiSaloon\Requests\Offers;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Offers\Offer;

class GetOffer extends Request
{
    protected Method $method = Method::GET;
    
    public function __construct(private int $offerId) {}
    
    public function resolveEndpoint(): string { return "/offers/{$this->offerId}"; }
    
    public function createDtoFromResponse($response): Offer
    {
        return Offer::fromKajabiOffer($response->json('data'));
    }
}
