<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Courses;

use WooNinja\LMSContracts\Contracts\DTOs\Courses\CourseInterface;

final class Course implements CourseInterface
{
    public function __construct(
        public int         $id,
        public string      $name,
        public string      $slug,
        public string|null $subtitle,
        public int         $product_id,
        public ?string     $description,
        public ?string     $course_card_text,
        public ?string     $intro_video_youtube,
        public ?string     $contact_information,
        public ?string     $keywords,
        public ?int        $duration,
        public string      $banner_image_url,
        public string      $course_card_image_url,
        public ?string     $intro_video_wistia_identifier,
        public array       $administrator_user_ids,
        public array       $chapter_ids,
        public bool        $reviews_enabled,
        public ?int        $user_id,
        public ?int        $instructor_id,
        // Additional Kajabi-specific fields
        public ?string     $thumbnail_url = null,
        public ?string     $created_at = null,
        public ?string     $updated_at = null,
    )
    {
    }

    /**
     * Create Course from Kajabi Course API response (legacy - for content structure)
     */
    public static function fromResponse(array $course): self
    {
        return self::fromKajabiCourse($course);
    }

    /**
     * Create Course from Kajabi Offer API response (NEW ARCHITECTURE)
     *
     * CRITICAL: Dashboard browses "Courses" which are actually OFFERS in Kajabi
     * - Offers are what users enroll in (not products directly)
     * - This transforms an Offer into a Course DTO for CourseInterface compatibility
     */
    public static function fromKajabiOffer(array $offer): self
    {
        // Extract product IDs from relationships
        $productIds = [];
        $firstProductId = 0;
        if (isset($offer['relationships']['products']['data']) && is_array($offer['relationships']['products']['data'])) {
            $productIds = array_map(fn($product) => (int) $product['id'], $offer['relationships']['products']['data']);
            $firstProductId = $productIds[0] ?? 0;
        }

        return new self(
            id: (int) $offer['id'], // Offer ID (dashboard thinks it's course_id)
            name: $offer['attributes']['title'] ?? $offer['attributes']['internal_title'] ?? 'Untitled Offer',
            slug: $offer['attributes']['token'] ?? '',
            subtitle: null,
            product_id: $firstProductId, // First product in the offer
            description: $offer['attributes']['description'] ?? null,
            course_card_text: $offer['attributes']['description'] ?? null,
            intro_video_youtube: null,
            contact_information: null,
            keywords: null,
            duration: null,
            banner_image_url: '',
            course_card_image_url: '',
            intro_video_wistia_identifier: null,
            administrator_user_ids: [],
            chapter_ids: $productIds, // List all products as "chapters" for reference
            reviews_enabled: false,
            user_id: null,
            instructor_id: null,
            thumbnail_url: null,
            created_at: $offer['attributes']['created_at'] ?? null,
            updated_at: $offer['attributes']['updated_at'] ?? null,
        );
    }

    /**
     * Create Course from Kajabi Product API response (legacy)
     *
     * In Kajabi, "Courses" as Thinkific knows them are actually "Products"
     * This method transforms a Product response into a Course DTO
     *
     * NOTE: As of new architecture, CourseService returns Offers, not Products
     * This method is kept for backward compatibility if needed
     */
    public static function fromKajabiProduct(array $product): self
    {
        // This is identical to fromKajabiCourse since both endpoints return similar structure
        // Products are what users enroll in, Courses are the content structure
        return self::fromKajabiCourse($product);
    }

    /**
     * Create Course from Kajabi Course/Product API response
     *
     * Note: Kajabi has two concepts:
     * - Product (what users buy/enroll in) - use fromKajabiProduct()
     * - Course (the content structure with modules/lessons) - use fromKajabiCourse()
     * This method handles both since the structure is similar
     */
    public static function fromKajabiCourse(array $course): self
    {
        // Extract product_id from relationships
        $productId = 0;
        if (isset($course['relationships']['products']['data'])) {
            $products = $course['relationships']['products']['data'];
            if (!empty($products) && is_array($products)) {
                $productId = (int) $products[0]['id']; // Take first product
            }
        }
        
        // Extract administrator/instructor IDs from relationships
        $administratorIds = [];
        $instructorId = null;
        
        if (isset($course['relationships']['instructors']['data'])) {
            $instructors = $course['relationships']['instructors']['data'];
            if (is_array($instructors)) {
                foreach ($instructors as $instructor) {
                    $adminId = (int) $instructor['id'];
                    $administratorIds[] = $adminId;
                    // Set the first instructor as the primary instructor
                    if ($instructorId === null) {
                        $instructorId = $adminId;
                    }
                }
            }
        }
        
        // Extract chapter/module IDs from relationships
        $chapterIds = [];
        if (isset($course['relationships']['modules']['data'])) {
            $modules = $course['relationships']['modules']['data'];
            if (is_array($modules)) {
                $chapterIds = array_map(fn($module) => (int) $module['id'], $modules);
            }
        } elseif (isset($course['relationships']['lessons']['data'])) {
            // Some Kajabi courses might have lessons directly
            $lessons = $course['relationships']['lessons']['data'];
            if (is_array($lessons)) {
                $chapterIds = array_map(fn($lesson) => (int) $lesson['id'], $lessons);
            }
        }
        
        return new self(
            id: (int) $course['id'],
            name: $course['attributes']['title'] ?? $course['attributes']['name'] ?? '',
            slug: $course['attributes']['slug'] ?? '',
            subtitle: $course['attributes']['subtitle'] ?? null,
            product_id: $productId,
            description: $course['attributes']['description'] ?? null,
            course_card_text: $course['attributes']['description'] ?? null,
            intro_video_youtube: null, // Kajabi doesn't expose this in the same way
            contact_information: null,
            keywords: $course['attributes']['keywords'] ?? null,
            duration: null, // Kajabi doesn't provide duration in the same format
            banner_image_url: $course['attributes']['thumbnail_url'] ?? '',
            course_card_image_url: $course['attributes']['thumbnail_url'] ?? '',
            intro_video_wistia_identifier: null,
            administrator_user_ids: $administratorIds,
            chapter_ids: $chapterIds,
            reviews_enabled: (bool) ($course['attributes']['reviews_enabled'] ?? false),
            user_id: null, // Kajabi doesn't have a single user owner
            instructor_id: $instructorId,
            // Kajabi-specific fields
            thumbnail_url: $course['attributes']['thumbnail_url'] ?? null,
            created_at: $course['attributes']['created_at'] ?? null,
            updated_at: $course['attributes']['updated_at'] ?? null,
        );
    }

}
