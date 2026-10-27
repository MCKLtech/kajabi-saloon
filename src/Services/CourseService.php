<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\CourseStructure;
use WooNinja\KajabiSaloon\DataTransferObjects\Lessons\Lesson;
use WooNinja\KajabiSaloon\DataTransferObjects\Modules\Module;
use WooNinja\KajabiSaloon\Requests\Courses\GetCourseStructure;
use WooNinja\KajabiSaloon\Requests\Products\GetCourseProducts;
use WooNinja\KajabiSaloon\Requests\Products\GetProduct;
use WooNinja\LMSContracts\Contracts\Services\CourseServiceInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Courses\CourseInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Products\ProductInterface;

/**
 * Course Service for Kajabi: courses are Kajabi course PRODUCTS
 *
 * - course_id = Product ID (a course product's id is also its /v1/courses id)
 * - Cohort courses are included; communities, coaching, podcasts and access
 *   groups are not
 * - What a contact enrolls in is an OFFER (a bundle of these courses): see
 *   $kajabi->products and $kajabi->bundles
 *
 * KAJABI CONCEPTS:
 * - PRODUCTS: Content items (courses, communities, coaching, memberships)
 * - OFFERS: Pricing/access packages that grant access to one or more products
 */
class CourseService extends Resource implements CourseServiceInterface
{
    /**
     * Get a Course (a course product) by Product ID
     *
     * @param int $course_id Product ID
     * @return CourseInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function get(int $course_id): CourseInterface
    {
        return Course::fromKajabiProduct(
            $this->connector->send(new GetProduct($course_id))->json('data')
        );
    }

    /**
     * List Courses: the site's course products, as Course DTOs
     *
     * Kajabi can't filter products by type, so non-course products are dropped
     * client-side and a page can hold fewer than `limit` courses.
     *
     * @param array $filters name_cont, description_cont, limit, max_pages
     * @return Paginator
     */
    public function courses(array $filters = []): Paginator
    {
        return $this->connector
            ->paginate(new GetCourseProducts($filters, $this->getDefaultSiteId()));
    }

    /**
     * The Kajabi product behind a Course (the course product itself)
     *
     * @param int $course_id Product ID
     * @return ProductInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function product(int $course_id): ProductInterface
    {
        return $this->connector->send(new GetProduct($course_id))->dtoOrFail();
    }

    /**
     * Get the chapters of a Course
     *
     * In Kajabi's architecture:
     * - Offers grant access to Products
     * - Products contain Courses (content structure)
     * - Courses contain Modules and Lessons
     *
     * This would need to:
     * 1. Get the Offer
     * 2. Find the Products in that Offer
     * 3. Get the Course content from those Products
     * 4. Return Modules/Lessons as "Chapters"
     *
     * @param int $productable_id Product ID
     * @return Paginator
     */
    public function chapters(int $productable_id): Paginator
    {
        throw new \Exception('Chapter listing is not supported: Kajabi uses Offers → Products → Courses → Modules → Lessons. Use $kajabi->courses->structure($productId) with a product id to read modules and lessons.');
    }

    /**
     * Modules and lessons of a course product, in order, from one request
     *
     * Takes a PRODUCT id (a "Course" product's id is also its /v1/courses id). Kajabi has no lesson or
     * module endpoints, so this is the only source of titles and order.
     *
     * @throws RequestException 404 for products that aren't courses, 403 for ids not on the account
     */
    public function structure(int $productId): CourseStructure
    {
        return $this->connector->send(new GetCourseStructure($productId))->dtoOrFail();
    }

    /**
     * Lessons of a course product, ordered by module then position
     *
     * @return Lesson[]
     * @throws RequestException
     */
    public function lessons(int $productId): array
    {
        return $this->structure($productId)->lessons;
    }

    /**
     * Modules of a course product, ordered by position
     *
     * @return Module[]
     * @throws RequestException
     */
    public function modules(int $productId): array
    {
        return $this->structure($productId)->modules;
    }
}
