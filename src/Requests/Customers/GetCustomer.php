<?php

namespace WooNinja\KajabiSaloon\Requests\Customers;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Customers\Customer;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;

class GetCustomer extends Request
{
    use JsonApiQuery;

    protected Method $method = Method::GET;

    public function __construct(private int $customerId) {}

    public function resolveEndpoint(): string
    {
        return "/customers/{$this->customerId}";
    }

    public function createDtoFromResponse($response): Customer
    {
        $data = $response->json('data');
        return Customer::fromKajabiCustomer($data);
    }
}
