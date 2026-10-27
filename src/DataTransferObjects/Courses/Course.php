<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Courses;

use WooNinja\LMSContracts\Contracts\DTOs\Courses\CourseInterface;

/**
 * $chapter_ids is kept for the CourseInterface contract, but holds different
 * things by source: product ids (fromKajabiOffer), module ids - or lesson ids
 * when a course has no modules - (fromKajabiCourse). Read $product_ids,
 * $module_ids and $lesson_ids instead; each always means one thing.
 */
final class Course implements CourseInterface
{
    /**
     * @param int[] $product_ids Products this offer grants, or the course's product (its own id)
     * @param int[] $module_ids  Module ids, from GET /v1/courses/{id}; empty for offers
     * @param int[] $lesson_ids  Lesson ids, from GET /v1/courses/{id}; empty for offers
     */
    public function __construct(
        public int         $id,
        public string      $name,
        public string      $slug,
        public string|null $subtitle,
        public ?int        $product_id,
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
        public array       $product_ids = [],
        public array       $module_ids = [],
        public array       $lesson_ids = [],
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
     * Create Course from Kajabi Offer API response
     *
     * @deprecated 3.0 An offer is a bundle of courses now: use ProductService::courses($offerId)
     *             for its course products. Removed in 4.0.
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
            product_id: (int) $offer['id'], //Offer ID
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
            product_ids: $productIds,
        );
    }

    /**
     * Create Course from a Kajabi course product (GET /v1/products or /v1/products/{id})
     *
     * id and product_id are the product id (a course product's id is also its
     * /v1/courses id). Product resources carry no modules, so chapter_ids,
     * module_ids and lesson_ids are empty.
     */
    public static function fromKajabiProduct(array $product): self
    {
        $course = self::fromKajabiCourse($product);
        $course->product_id ??= $course->id;

        return $course;
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
        // Extract product_id from relationships (Kajabi uses singular 'product' on
        // course content responses and plural 'products' on offer/product responses)
        $productId = null;
        $productData = $course['relationships']['product']['data'] ?? $course['relationships']['products']['data'] ?? null;
        if (!empty($productData)) {
            if (isset($productData['id'])) {
                // Singular form: { "data": { "id": "303", "type": "products" } }
                $productId = (int)$productData['id'];
            } elseif (is_array($productData)) {
                // Plural form: { "data": [ { "id": "303", "type": "products" }, ... ] }
                $productId = (int)$productData[0]['id'];
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
        
        $moduleIds = self::relationshipIds($course, 'modules');
        $lessonIds = self::relationshipIds($course, 'lessons');

        // chapter_ids: modules, or lessons when a course has no modules
        $chapterIds = isset($course['relationships']['modules']['data']) ? $moduleIds : $lessonIds;

        // GET /v1/courses/{id} sends no product relationship, but a course's id is
        // its product's id (verified live), so product_ids falls back to it.
        // product_id stays null then, as before.
        $productIds = array_values(array_unique(array_merge(
            $productId !== null ? [$productId] : [],
            self::relationshipIds($course, 'products')
        )));
        if ($productIds === []) {
            $productIds = [(int) $course['id']];
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
            product_ids: $productIds,
            module_ids: $moduleIds,
            lesson_ids: $lessonIds,
        );
    }

    /**
     * @return int[]
     */
    private static function relationshipIds(array $resource, string $name): array
    {
        $data = $resource['relationships'][$name]['data'] ?? null;

        if (!is_array($data)) {
            return [];
        }

        // To-one: { "data": { "id": "303" } }
        if (isset($data['id'])) {
            return [(int) $data['id']];
        }

        // To-many: { "data": [ { "id": "303" }, ... ] }
        return array_map(fn($item) => (int) $item['id'], $data);
    }
}
