<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Customers\Customer;
use WooNinja\KajabiSaloon\Requests\Customers\GetCustomer;
use WooNinja\KajabiSaloon\Requests\Customers\GetCustomers;

class CustomerService extends Resource
{
    /**
     * Get a Customer by ID
     */
    public function get(int $customer_id): Customer
    {
        return $this->connector
            ->send(new GetCustomer($customer_id))
            ->dtoOrFail();
    }

    /**
     * Get a list of Customers
     */
    public function customers(array $filters = []): Paginator
    {
        return $this->connector
            ->paginate(new GetCustomers($filters, $this->getDefaultSiteId()));
    }
}
