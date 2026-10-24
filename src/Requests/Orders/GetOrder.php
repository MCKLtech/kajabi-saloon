<?php

namespace WooNinja\KajabiSaloon\Requests\Orders;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Orders\Order;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;

class GetOrder extends Request
{
    use JsonApiQuery;

    protected Method $method = Method::GET;

    public function __construct(
        private int $orderId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/orders/{$this->orderId}";
    }

    /**
     * Items and the customer's email/name are only in `included`
     */
    protected function defaultQuery(): array
    {
        return ['include' => 'order_items,customer'];
    }

    public function createDtoFromResponse($response): Order
    {
        return Order::fromKajabiOrder(
            $response->json('data'),
            Order::includedMap($response->json('included', []))
        );
    }
}
