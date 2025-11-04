<?php

namespace WooNinja\KajabiSaloon\Requests\Orders;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Orders\Order;

class GetOrder extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private int $orderId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/orders/{$this->orderId}";
    }

    public function createDtoFromResponse($response): Order
    {
        $data = $response->json('data');
        
        return Order::fromKajabiOrder($data);
    }
}
