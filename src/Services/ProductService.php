<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffers;
use WooNinja\LMSContracts\Contracts\Services\ProductServiceInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Products\ProductInterface;

/**
 * Product Service for Kajabi (returns Offers for Thinkific compatibility)
 *
 * CRITICAL ARCHITECTURE:
 * - Dashboard browses "Products" that users can enroll in
 * - In Kajabi, users enroll by being granted OFFERS (not Products directly)
 * - This service returns OFFERS masquerading as "Products"
 * - When dashboard says "enroll in Product 789", it's actually "grant Offer 789"
 *
 * WHY OFFERS NOT PRODUCTS:
 * - Kajabi PRODUCTS are content items (courses, communities, etc.)
 * - Kajabi OFFERS are pricing/access packages that grant access to products
 * - You can't enroll a user in a Product - you grant them an Offer
 * - One Offer can include multiple Products
 *
 * For Thinkific compatibility:
 * - "Product" = Offer (what you enroll in)
 * - product_id = offer_id
 * - Offer DTO implements ProductInterface
 */
class ProductService extends Resource implements ProductServiceInterface
{
    /**
     * Get a Product by ID (actually gets an Offer)
     *
     * @param int $product_id Offer ID (dashboard thinks it's product_id)
     * @return ProductInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function get(int $product_id): ProductInterface
    {
        // Get the Offer and return it as a Product
        return $this->connector
            ->send(new GetOffer($product_id))
            ->dtoOrFail();
    }

    /**
     * Get a list of Products (actually lists Offers)
     *
     * @param array $filters
     * @return Paginator Returns Offer DTOs implementing ProductInterface
     */
    public function products(array $filters = []): Paginator
    {
        // Return Offers instead of Products
        // Each Offer implements ProductInterface, so dashboard sees them as "Products"
        return $this->connector
            ->paginate(new GetOffers($filters, $this->getDefaultSiteId()));
    }

    /**
     * Return the Course(s) associated with a Product
     *
     * Since "Products" are actually Offers, this returns the given Offer itself.
     * A "Product" (Offer) has one "Course" (itself) that users enroll in.
     *
     * @param int $product_id Offer ID
     * @return array Array containing the single Offer
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function courses(int $product_id): array
    {
        // Get the Offer and return it as the single course for this product
        $offer = $this->connector
            ->send(new GetOffer($product_id))
            ->dtoOrFail();

        return [$offer];
    }

    /**
     * List Related Products (offers)
     *
     * Not implemented for Kajabi.
     *
     * @param int $product_id Offer ID
     * @return Paginator
     * @throws \BadMethodCallException
     */
    public function related(int $product_id): Paginator
    {
        throw new \BadMethodCallException('Related products are not supported in Kajabi');
    }
}
