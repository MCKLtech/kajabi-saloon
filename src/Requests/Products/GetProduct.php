<?php

namespace WooNinja\KajabiSaloon\Requests\Products;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Products\Product;

class GetProduct extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private int $productId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/products/{$this->productId}";
    }

    public function createDtoFromResponse($response): Product
    {
        $data = $response->json('data');
        
        return Product::fromKajabiProduct($data);
    }
}
