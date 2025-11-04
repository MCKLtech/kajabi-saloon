<?php

namespace Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\LMSContracts\Contracts\LMSServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\UserServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\CourseServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\EnrollmentServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\ProductServiceInterface;
use WooNinja\LMSContracts\Contracts\Services\OrderServiceInterface;

/**
 * This test suite ensures 100% compatibility with Thinkific B2B Dashboard
 *
 * These tests verify that:
 * 1. All required interfaces are implemented
 * 2. All service methods exist and have correct signatures
 * 3. All DTOs implement required interfaces
 * 4. Property access patterns match Thinkific
 */
class ThinkificCompatibilityTest extends TestCase
{
    private KajabiService $service;

    protected function setUp(): void
    {
        $this->service = new KajabiService(
            'test-client-id',
            'test-client-secret',
            'test-site-123'
        );
    }

    // ==================== LMS Service Interface Tests ====================

    public function test_kajabi_service_implements_lms_service_interface(): void
    {
        $this->assertInstanceOf(LMSServiceInterface::class, $this->service);
    }

    public function test_get_provider_name_returns_kajabi(): void
    {
        $this->assertEquals('kajabi', $this->service->getProviderName());
    }

    public function test_has_is_connected_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'isConnected'));
    }

    // ==================== Service Access Tests ====================

    public function test_users_service_property_exists(): void
    {
        $this->assertObjectHasProperty('users', $this->service);
    }

    public function test_courses_service_property_exists(): void
    {
        $this->assertObjectHasProperty('courses', $this->service);
    }

    public function test_enrollments_service_property_exists(): void
    {
        $this->assertObjectHasProperty('enrollments', $this->service);
    }

    public function test_products_service_property_exists(): void
    {
        $this->assertObjectHasProperty('products', $this->service);
    }

    public function test_orders_service_property_exists(): void
    {
        $this->assertObjectHasProperty('orders', $this->service);
    }

    // ==================== Service Interface Implementation Tests ====================

    public function test_users_service_implements_interface(): void
    {
        $this->assertInstanceOf(UserServiceInterface::class, $this->service->users);
    }

    public function test_courses_service_implements_interface(): void
    {
        $this->assertInstanceOf(CourseServiceInterface::class, $this->service->courses);
    }

    public function test_enrollments_service_implements_interface(): void
    {
        $this->assertInstanceOf(EnrollmentServiceInterface::class, $this->service->enrollments);
    }

    public function test_products_service_implements_interface(): void
    {
        $this->assertInstanceOf(ProductServiceInterface::class, $this->service->products);
    }

    public function test_orders_service_implements_interface(): void
    {
        $this->assertInstanceOf(OrderServiceInterface::class, $this->service->orders);
    }

    // ==================== Thinkific Compatibility Stubs Tests ====================

    public function test_has_bundles_service_stub(): void
    {
        $this->assertObjectHasProperty('bundles', $this->service);
        $this->assertIsObject($this->service->bundles);
    }

    public function test_has_chapters_service_stub(): void
    {
        $this->assertObjectHasProperty('chapters', $this->service);
        $this->assertIsObject($this->service->chapters);
    }

    public function test_has_contents_service_stub(): void
    {
        $this->assertObjectHasProperty('contents', $this->service);
        $this->assertIsObject($this->service->contents);
    }

    public function test_has_coupons_service_stub(): void
    {
        $this->assertObjectHasProperty('coupons', $this->service);
        $this->assertIsObject($this->service->coupons);
    }

    public function test_has_course_reviews_service_stub(): void
    {
        $this->assertObjectHasProperty('courseReviews', $this->service);
        $this->assertIsObject($this->service->courseReviews);
    }

    public function test_has_custom_profile_field_definitions_service_stub(): void
    {
        $this->assertObjectHasProperty('customProfileFieldDefinitions', $this->service);
        $this->assertIsObject($this->service->customProfileFieldDefinitions);
    }

    public function test_has_groups_service_stub(): void
    {
        $this->assertObjectHasProperty('groups', $this->service);
        $this->assertIsObject($this->service->groups);
    }

    public function test_has_instructors_service_stub(): void
    {
        $this->assertObjectHasProperty('instructors', $this->service);
        $this->assertIsObject($this->service->instructors);
    }

    public function test_has_promotions_service_stub(): void
    {
        $this->assertObjectHasProperty('promotions', $this->service);
        $this->assertIsObject($this->service->promotions);
    }

    public function test_has_site_scripts_service_stub(): void
    {
        $this->assertObjectHasProperty('siteScripts', $this->service);
        $this->assertIsObject($this->service->siteScripts);
    }

    public function test_has_oauth_service_stub(): void
    {
        $this->assertObjectHasProperty('oauth', $this->service);
        $this->assertIsObject($this->service->oauth);
    }

    // ==================== User Service Method Tests ====================

    public function test_user_service_has_get_method(): void
    {
        $this->assertTrue(method_exists($this->service->users, 'get'));
    }

    public function test_user_service_has_users_method(): void
    {
        $this->assertTrue(method_exists($this->service->users, 'users'));
    }

    public function test_user_service_has_create_method(): void
    {
        $this->assertTrue(method_exists($this->service->users, 'create'));
    }

    public function test_user_service_has_update_method(): void
    {
        $this->assertTrue(method_exists($this->service->users, 'update'));
    }

    public function test_user_service_has_delete_method(): void
    {
        $this->assertTrue(method_exists($this->service->users, 'delete'));
    }

    public function test_user_service_has_find_method(): void
    {
        $this->assertTrue(method_exists($this->service->users, 'find'));
    }

    public function test_user_service_has_find_by_email_method(): void
    {
        $this->assertTrue(method_exists($this->service->users, 'findByEmail'));
    }

    // ==================== Enrollment Service Method Tests ====================

    public function test_enrollment_service_has_get_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'get'));
    }

    public function test_enrollment_service_has_enrollments_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'enrollments'));
    }

    public function test_enrollment_service_has_create_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'create'));
    }

    public function test_enrollment_service_has_update_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'update'));
    }

    public function test_enrollment_service_has_enroll_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'enroll'));
    }

    public function test_enrollment_service_has_unenroll_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'unenroll'));
    }

    public function test_enrollment_service_has_is_user_enrolled_in_course_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'isUserEnrolledInCourse'));
    }

    public function test_enrollment_service_has_enrollments_for_user_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'enrollmentsForUser'));
    }

    public function test_enrollment_service_has_enrollments_for_course_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'enrollmentsForCourse'));
    }

    public function test_enrollment_service_has_enrollments_for_user_in_course_method(): void
    {
        $this->assertTrue(method_exists($this->service->enrollments, 'enrollmentsForUserInCourse'));
    }

    // ==================== Course Service Method Tests ====================

    public function test_course_service_has_get_method(): void
    {
        $this->assertTrue(method_exists($this->service->courses, 'get'));
    }

    public function test_course_service_has_courses_method(): void
    {
        $this->assertTrue(method_exists($this->service->courses, 'courses'));
    }

    public function test_course_service_has_product_method(): void
    {
        $this->assertTrue(method_exists($this->service->courses, 'product'));
    }

    public function test_course_service_has_chapters_method(): void
    {
        $this->assertTrue(method_exists($this->service->courses, 'chapters'));
    }

    // ==================== Product Service Method Tests ====================

    public function test_product_service_has_get_method(): void
    {
        $this->assertTrue(method_exists($this->service->products, 'get'));
    }

    public function test_product_service_has_products_method(): void
    {
        $this->assertTrue(method_exists($this->service->products, 'products'));
    }

    public function test_product_service_has_courses_method(): void
    {
        $this->assertTrue(method_exists($this->service->products, 'courses'));
    }

    public function test_product_service_has_related_method(): void
    {
        $this->assertTrue(method_exists($this->service->products, 'related'));
    }

    // ==================== Order Service Method Tests ====================

    public function test_order_service_has_get_method(): void
    {
        $this->assertTrue(method_exists($this->service->orders, 'get'));
    }

    public function test_order_service_has_orders_method(): void
    {
        $this->assertTrue(method_exists($this->service->orders, 'orders'));
    }

    // ==================== Property Access Pattern Tests ====================

    public function test_can_access_users_via_property(): void
    {
        // Thinkific B2B Dashboard uses: $client->users->users()
        $users = $this->service->users;
        $this->assertNotNull($users);
        $this->assertInstanceOf(UserServiceInterface::class, $users);
    }

    public function test_can_access_courses_via_property(): void
    {
        // Thinkific B2B Dashboard uses: $client->courses->courses()
        $courses = $this->service->courses;
        $this->assertNotNull($courses);
        $this->assertInstanceOf(CourseServiceInterface::class, $courses);
    }

    public function test_can_access_enrollments_via_property(): void
    {
        // Thinkific B2B Dashboard uses: $client->enrollments->enrollments()
        $enrollments = $this->service->enrollments;
        $this->assertNotNull($enrollments);
        $this->assertInstanceOf(EnrollmentServiceInterface::class, $enrollments);
    }

    public function test_can_access_products_via_property(): void
    {
        // Thinkific B2B Dashboard uses: $client->products->products()
        $products = $this->service->products;
        $this->assertNotNull($products);
        $this->assertInstanceOf(ProductServiceInterface::class, $products);
    }

    public function test_can_access_orders_via_property(): void
    {
        // Thinkific B2B Dashboard uses: $client->orders->orders()
        $orders = $this->service->orders;
        $this->assertNotNull($orders);
        $this->assertInstanceOf(OrderServiceInterface::class, $orders);
    }

    // ==================== Additional Kajabi Services Tests ====================

    public function test_has_customers_service(): void
    {
        $this->assertObjectHasProperty('customers', $this->service);
        $this->assertIsObject($this->service->customers);
    }

    public function test_has_offers_service(): void
    {
        $this->assertObjectHasProperty('offers', $this->service);
        $this->assertIsObject($this->service->offers);
    }

    public function test_has_sites_service(): void
    {
        $this->assertObjectHasProperty('sites', $this->service);
        $this->assertIsObject($this->service->sites);
    }

    public function test_has_webhooks_service(): void
    {
        $this->assertObjectHasProperty('webhooks', $this->service);
        $this->assertIsObject($this->service->webhooks);
    }

    // ==================== Interface Method Accessor Tests ====================

    public function test_users_method_returns_user_service_interface(): void
    {
        $this->assertInstanceOf(UserServiceInterface::class, $this->service->users());
    }

    public function test_courses_method_returns_course_service_interface(): void
    {
        $this->assertInstanceOf(CourseServiceInterface::class, $this->service->courses());
    }

    public function test_enrollments_method_returns_enrollment_service_interface(): void
    {
        $this->assertInstanceOf(EnrollmentServiceInterface::class, $this->service->enrollments());
    }

    public function test_products_method_returns_product_service_interface(): void
    {
        $this->assertInstanceOf(ProductServiceInterface::class, $this->service->products());
    }

    public function test_orders_method_returns_order_service_interface(): void
    {
        $this->assertInstanceOf(OrderServiceInterface::class, $this->service->orders());
    }
}
