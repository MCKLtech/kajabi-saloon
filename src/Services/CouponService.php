<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;

/**
 * Coupon Service - Compatibility Stub
 *
 * Kajabi handles coupons differently than Thinkific.
 * Kajabi uses Offers with pricing options instead of separate coupon/promotion systems.
 */
class CouponService extends Resource
{
    /**
     * Get Coupon by ID
     *
     * @param int $coupon_id
     * @return mixed
     * @throws \Exception
     */
    public function get(int $coupon_id)
    {
        throw new \Exception('Coupons are not supported in Kajabi API. Kajabi uses Offers for pricing and promotions.');
    }

    /**
     * Get Coupons for a given Promotion
     *
     * @param int $promotion_id
     * @return Paginator
     * @throws \Exception
     */
    public function coupons(int $promotion_id): Paginator
    {
        throw new \Exception('Coupons are not supported in Kajabi API. Kajabi uses Offers for pricing and promotions.');
    }

    /**
     * Create a Coupon
     *
     * @param mixed $coupon
     * @return mixed
     * @throws \Exception
     */
    public function create($coupon)
    {
        throw new \Exception('Coupons are not supported in Kajabi API. Kajabi uses Offers for pricing and promotions.');
    }

    /**
     * Update a Coupon
     *
     * @param mixed $coupon
     * @return Response
     * @throws \Exception
     */
    public function update($coupon): Response
    {
        throw new \Exception('Coupons are not supported in Kajabi API. Kajabi uses Offers for pricing and promotions.');
    }

    /**
     * Delete a Coupon
     *
     * @param int $coupon_id
     * @return void
     * @throws \Exception
     */
    public function delete(int $coupon_id): void
    {
        throw new \Exception('Coupons are not supported in Kajabi API. Kajabi uses Offers for pricing and promotions.');
    }
}
