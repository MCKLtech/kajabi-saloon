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
     * Page size for id-map walks. Kajabi doesn't cap page[size] on /customers
     * (5000 returned every row live); 1000 keeps responses small enough to be quick.
     */
    public const ID_MAP_PAGE_SIZE = 1000;

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

    /**
     * Contact ids for the given customer ids, as customer_id => contact_id
     *
     * Kajabi has no filter[id] on /customers (unknown filters are silently
     * ignored), so this walks the listing - asking only for the contact
     * relationship - and stops once every id is found. Ids not on the site
     * are left out of the result.
     *
     * @param int[] $customerIds
     * @return array<int, int>
     */
    public function contactIds(array $customerIds, array $filters = []): array
    {
        $wanted = array_fill_keys(array_map('intval', $customerIds), true);

        if ($wanted === []) {
            return [];
        }

        $map = [];

        foreach ($this->idMapPaginator($filters)->items() as $customer) {
            if (isset($wanted[$customer->id]) && $customer->contact_id !== null) {
                $map[$customer->id] = $customer->contact_id;
                unset($wanted[$customer->id]);

                if ($wanted === []) {
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Every customer on the site as customer_id => contact_id (a few requests for thousands of customers)
     *
     * @return array<int, int>
     */
    public function contactIdMap(array $filters = []): array
    {
        $map = [];

        foreach ($this->idMapPaginator($filters)->items() as $customer) {
            if ($customer->contact_id !== null) {
                $map[$customer->id] = $customer->contact_id;
            }
        }

        return $map;
    }

    /**
     * Customers with only the contact relationship. Sparse fieldsets drop
     * relationships that aren't named, so "contact" must be listed.
     */
    private function idMapPaginator(array $filters): Paginator
    {
        return $this->customers([
            'fields[customers]' => 'contact',
            'limit' => self::ID_MAP_PAGE_SIZE,
        ] + $filters);
    }
}
