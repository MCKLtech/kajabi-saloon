<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;

/**
 * Promotion Service - Compatibility Stub
 *
 * Kajabi uses Offers instead of separate Promotions.
 * Use the OfferService for similar functionality.
 */
class PromotionService extends Resource
{
    /**
     * Get a Promotion by ID
     *
     * @param int $promotion_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $promotion_id)
    {
        throw new \Exception('Promotions are not supported in Kajabi. Use Offers instead via the OfferService.');
    }

    /**
     * Get a list of Promotions
     *
     * @param array $filters
     * @return Paginator
     * @throws \Exception
     */
    public function promotions(array $filters = []): Paginator
    {
        throw new \Exception('Promotions are not supported in Kajabi. Use Offers instead via the OfferService.');
    }

    /**
     * Create a Promotion
     *
     * @param mixed $promotion
     * @return mixed
     * @throws \Exception
     */
    public function create($promotion)
    {
        throw new \Exception('Promotions are not supported in Kajabi. Use Offers instead via the OfferService.');
    }

    /**
     * Update a Promotion
     *
     * @param mixed $promotion
     * @return Response
     * @throws \Exception
     */
    public function update($promotion): Response
    {
        throw new \Exception('Promotions are not supported in Kajabi. Use Offers instead via the OfferService.');
    }

    /**
     * Delete a Promotion
     *
     * @param int $promotion_id
     * @return void
     * @throws \Exception
     */
    public function delete(int $promotion_id): void
    {
        throw new \Exception('Promotions are not supported in Kajabi. Use Offers instead via the OfferService.');
    }
}
