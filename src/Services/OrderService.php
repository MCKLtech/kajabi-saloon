<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Orders\Order;
use WooNinja\KajabiSaloon\Requests\Orders\GetOrder;
use WooNinja\KajabiSaloon\Requests\Orders\GetOrders;
use WooNinja\LMSContracts\Contracts\Services\OrderServiceInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Orders\OrderInterface;

class OrderService extends Resource implements OrderServiceInterface
{
    /**
     * Get an Order by ID
     *
     * @param int $order_id
     * @return OrderInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function get(int $order_id): OrderInterface
    {
        return $this->connector
            ->send(new GetOrder($order_id))
            ->dtoOrFail();
    }

    /**
     * Get a list of Orders
     *
     * @param array $filters
     * @return Paginator
     */
    public function orders(array $filters = []): Paginator
    {
        return $this->connector
            ->paginate(new GetOrders($filters, $this->getDefaultSiteId()));
    }
}
