<?php

namespace WooNinja\KajabiSaloon\Requests\Products;

use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\DataTransferObjects\Products\Product;

/**
 * GET /v1/products, keeping only course products (cohort courses included), as Course DTOs
 *
 * Kajabi can't filter products by type or id, so both happen client-side.
 */
class GetCourseProducts extends GetProducts
{
    /**
     * @param int[]|null $productIds Only these products
     */
    public function __construct(
        array $filters = [],
        ?string $defaultSiteId = null,
        private ?array $productIds = null,
    ) {
        parent::__construct($filters, $defaultSiteId);
    }

    public function createDtoFromResponse($response): array
    {
        $courses = [];

        foreach ($response->json('data', []) as $product) {
            if (!Product::fromKajabiProduct($product)->isCourse()) {
                continue;
            }

            if ($this->productIds !== null && !in_array((int) $product['id'], $this->productIds, true)) {
                continue;
            }

            $courses[] = Course::fromKajabiProduct($product);
        }

        return $courses;
    }
}
