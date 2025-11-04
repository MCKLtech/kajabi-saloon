<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffers;
use WooNinja\LMSContracts\Contracts\Services\CourseServiceInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Courses\CourseInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Products\ProductInterface;

/**
 * Course Service for Kajabi (returns Offers for Thinkific compatibility)
 *
 * CRITICAL ARCHITECTURE CHANGE:
 * - Dashboard thinks it's browsing "Courses" that users can enroll in
 * - In Kajabi, users enroll by being granted OFFERS (not Products directly)
 * - This service returns OFFERS masquerading as "Courses/Products"
 * - When dashboard says "enroll in Course 789", it's actually "grant Offer 789"
 *
 * WHY THIS WORKS:
 * - Offer DTO implements ProductInterface (looks like a Product to dashboard)
 * - Dashboard gets enrollable items (offers) not content items (products)
 * - EnrollmentService receives offer_id in course_id parameter
 * - Clean, simple enrollment: just grant/revoke the offer
 *
 * KAJABI CONCEPTS:
 * - PRODUCTS: Content items (courses, communities, coaching, memberships)
 * - OFFERS: Pricing/access packages that grant access to one or more products
 * - PURCHASES: When a user is granted an offer (their "enrollment")
 *
 * For Thinkific compatibility:
 * - "Course" = Offer (what you enroll in)
 * - course_id = offer_id
 */
class CourseService extends Resource implements CourseServiceInterface
{
    /**
     * Get a Course by ID (actually gets an Offer)
     *
     * @param int $course_id Offer ID (dashboard thinks it's course ID)
     * @return CourseInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function get(int $course_id): CourseInterface
    {
        // Get the Offer
        $response = $this->connector->send(new GetOffer($course_id));
        $offerData = $response->json('data');

        // Transform Offer to Course DTO for CourseInterface compatibility
        // We use Course::fromKajabiOffer() which transforms offer to course structure
        return \WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course::fromKajabiOffer($offerData);
    }

    /**
     * Get a list of Courses (actually lists Offers)
     *
     * @param array $filters
     * @return Paginator Returns Offer DTOs implementing ProductInterface
     */
    public function courses(array $filters = []): Paginator
    {
        // Return Offers instead of Products
        // Each Offer implements ProductInterface, so dashboard sees them as "Products/Courses"
        return $this->connector
            ->paginate(new GetOffers($filters, $this->getDefaultSiteId()));
    }

    /**
     * Return the associated Product of a Course
     *
     * Since "Courses" are actually Offers in this architecture:
     * - An Offer can grant access to multiple Products
     * - This method returns the first/primary product from the offer
     *
     * @param int $course_id Offer ID
     * @return ProductInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function product(int $course_id): ProductInterface
    {
        // Get the offer
        $response = $this->connector->send(new GetOffer($course_id));
        $offerData = $response->json('data');

        // The Offer DTO already implements ProductInterface
        // So we can return it directly as a "Product"
        return \WooNinja\KajabiSaloon\DataTransferObjects\Offers\Offer::fromKajabiOffer($offerData);
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
     * @param int $productable_id Offer ID
     * @return Paginator
     */
    public function chapters(int $productable_id): Paginator
    {
        throw new \Exception('Chapter listing not yet implemented for Kajabi. Kajabi uses: Offers → Products → Courses → Modules → Lessons hierarchy.');
    }
}
