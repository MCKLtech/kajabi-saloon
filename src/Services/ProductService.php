<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\DataTransferObjects\Products\Product;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffers;
use WooNinja\KajabiSaloon\Requests\Products\GetProduct;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
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
 * - "Product" = Offer (what you enroll in), a Bundle: productable_type 'Bundle'
 * - product_id = offer_id
 * - Offer DTO implements ProductInterface
 * - courses() = the offer's course products, one Course each
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
     * The courses of a Product (an Offer): one Course per course product it grants
     *
     * Communities, coaching, podcasts and access groups are left out; cohort
     * courses are kept. Course::$id and $product_id are the Kajabi product id.
     * Built from the cached product catalog (see courseProductsForOffer()), so
     * module ids aren't loaded: use $kajabi->courses->structure($id) for those.
     *
     * @param int $product_id Offer ID
     * @return array<Course>
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function courses(int $product_id): array
    {
        return array_values(array_map(
            fn(array $product) => Course::fromKajabiProduct($product),
            $this->courseProductsForOffer($product_id)
        ));
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

    /**
     * Kajabi PRODUCTS themselves (GET /v1/products), not the offers products() returns
     *
     * @param array $filters name_cont, description_cont, exclude_product_types, limit, max_pages
     *                       (filter[status_eq] is in the spec but ignored by the API)
     */
    public function kajabiProducts(array $filters = []): Paginator
    {
        return $this->connector
            ->paginate(new GetProducts($filters, $this->getDefaultSiteId()));
    }

    /**
     * One Kajabi product (GET /v1/products/{id})
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function kajabiProduct(int $product_id): Product
    {
        return $this->connector
            ->send(new GetProduct($product_id))
            ->dtoOrFail();
    }

    /**
     * Ids of the course products an offer grants, in the offer's order
     *
     * The offer's products (GET /v1/offers/{id}) intersected with the site's
     * course products (GET /v1/products, cohort courses included). Both
     * lookups are cached for KajabiService::cacheTtl(), so a change to an
     * offer's products shows up once its entry expires.
     *
     * @return int[]
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function courseProductIdsForOffer(int $offerId): array
    {
        return array_keys($this->courseProductsForOffer($offerId));
    }

    /**
     * Product resources (JSON:API data) of the course products an offer grants, keyed by id, in the offer's order
     *
     * @param array|null $offer The offer resource, when the caller already fetched it (refreshes its cache entry)
     * @return array<int, array>
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function courseProductsForOffer(int $offerId, ?array $offer = null): array
    {
        $courses = $this->courseProductResources();
        $granted = [];

        foreach ($this->offerProductIds($offerId, $offer) as $productId) {
            if (isset($courses[$productId])) {
                $granted[$productId] = $courses[$productId];
            }
        }

        return $granted;
    }

    /**
     * Every course product on the site (cohort courses included), keyed by id, cached
     *
     * @return array<int, array> product resources (JSON:API data)
     */
    public function courseProductResources(): array
    {
        $cache = $this->kajabi->cache();
        $key = $this->cacheKey('course_products');
        $products = $cache->get($key);

        if (!is_array($products)) {
            $products = [];

            foreach ($this->kajabiProducts(['limit' => 100]) as $response) {
                foreach ($response->json('data', []) as $product) {
                    if (Product::fromKajabiProduct($product)->isCourse()) {
                        $products[] = $product;
                    }
                }
            }

            $cache->set($key, $products, $this->kajabi->cacheTtl());
        }

        // Stored as a list: some cache stores don't keep integer keys
        $byId = [];
        foreach ($products as $product) {
            $byId[(int) $product['id']] = $product;
        }

        return $byId;
    }

    /**
     * @return int[] every product the offer grants, course or not
     */
    private function offerProductIds(int $offerId, ?array $offer): array
    {
        $cache = $this->kajabi->cache();
        $key = $this->cacheKey("offer_products:{$offerId}");

        if ($offer === null) {
            $ids = $cache->get($key);

            if (is_array($ids)) {
                return $ids;
            }

            $offer = $this->connector->send(new GetOffer($offerId))->json('data');
        }

        $ids = array_map(fn(array $product) => (int) $product['id'], $offer['relationships']['products']['data'] ?? []);
        $cache->set($key, $ids, $this->kajabi->cacheTtl());

        return $ids;
    }

    /**
     * The products that have modules, lessons and course progress - the ones
     * worth polling. Cohort courses are excluded (they have no progress API);
     * courseProductsForOffer() keeps them, since a member can still hold one.
     *
     * @return Product[]
     */
    public function courseProducts(array $filters = []): array
    {
        $products = $this->kajabiProducts(
            ['exclude_product_types' => GetProducts::COHORT_COURSE, 'limit' => 100] + $filters
        );

        $courses = [];

        foreach ($products->items() as $product) {
            if ($product->isCourse()) {
                $courses[] = $product;
            }
        }

        return $courses;
    }
}
