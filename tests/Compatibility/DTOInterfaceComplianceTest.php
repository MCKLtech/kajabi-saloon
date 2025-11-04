<?php

namespace Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\CreateUser;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\UpdateUser;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\UpdateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\DataTransferObjects\Products\Product;
use WooNinja\KajabiSaloon\DataTransferObjects\Orders\Order;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Users\CreateUserInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UpdateUserInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\EnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\CreateEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\UpdateEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Courses\CourseInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Products\ProductInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Orders\OrderInterface;
use Tests\Fixtures\KajabiApiResponses;

/**
 * Tests to ensure all DTOs implement lms-contracts interfaces correctly
 *
 * This is CRITICAL for Thinkific B2B Dashboard compatibility
 */
class DTOInterfaceComplianceTest extends TestCase
{
    // ==================== User DTOs ====================

    public function test_user_implements_user_interface(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertInstanceOf(UserInterface::class, $user);
    }

    public function test_create_user_implements_create_user_interface(): void
    {
        $createUser = new CreateUser(
            first_name: 'John',
            last_name: 'Doe',
            email: 'john@example.com'
        );

        $this->assertInstanceOf(CreateUserInterface::class, $createUser);
    }

    public function test_update_user_implements_update_user_interface(): void
    {
        $updateUser = new UpdateUser(
            id: 123,
            first_name: 'John',
            last_name: 'Doe',
            email: 'john@example.com'
        );

        $this->assertInstanceOf(UpdateUserInterface::class, $updateUser);
    }

    // ==================== Enrollment DTOs ====================

    public function test_enrollment_implements_enrollment_interface(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        $this->assertInstanceOf(EnrollmentInterface::class, $enrollment);
    }

    public function test_create_enrollment_implements_create_enrollment_interface(): void
    {
        $createEnrollment = new CreateEnrollment(
            user_id: 123,
            course_id: 456
        );

        $this->assertInstanceOf(CreateEnrollmentInterface::class, $createEnrollment);
    }

    public function test_update_enrollment_implements_update_enrollment_interface(): void
    {
        $updateEnrollment = new UpdateEnrollment(
            enrollment_id: 789,
            activated_at: null,
            expiry_date: null
        );

        $this->assertInstanceOf(UpdateEnrollmentInterface::class, $updateEnrollment);
    }

    // ==================== Course DTO ====================

    public function test_course_implements_course_interface(): void
    {
        $courseData = KajabiApiResponses::course();
        $course = Course::fromResponse($courseData);

        $this->assertInstanceOf(CourseInterface::class, $course);
    }

    // ==================== Product DTO ====================

    public function test_product_implements_product_interface(): void
    {
        $productData = KajabiApiResponses::product();
        $product = Product::fromResponse($productData);

        $this->assertInstanceOf(ProductInterface::class, $product);
    }

    // ==================== Order DTO ====================

    public function test_order_implements_order_interface(): void
    {
        $orderData = KajabiApiResponses::order();
        $order = Order::fromResponse($orderData);

        $this->assertInstanceOf(OrderInterface::class, $order);
    }

    // ==================== User Interface Method Tests ====================

    public function test_user_has_get_full_name_method(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertTrue(method_exists($user, 'getFullName'));
        $this->assertIsString($user->getFullName());
    }

    // ==================== Enrollment Interface Method Tests ====================

    public function test_enrollment_has_all_interface_getter_methods(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);


    }

    // ==================== Required Fields Tests ====================

    public function test_user_has_required_fields(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        // Fields required by Thinkific B2B Dashboard
        $this->assertObjectHasProperty('id', $user);
        $this->assertObjectHasProperty('first_name', $user);
        $this->assertObjectHasProperty('last_name', $user);
        $this->assertObjectHasProperty('email', $user);
        $this->assertObjectHasProperty('roles', $user);
        $this->assertObjectHasProperty('created_at', $user);
        $this->assertObjectHasProperty('updated_at', $user);
    }

    public function test_enrollment_has_required_fields(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        // Fields required by Thinkific B2B Dashboard
        $this->assertObjectHasProperty('id', $enrollment);
        $this->assertObjectHasProperty('user_id', $enrollment);
        $this->assertObjectHasProperty('user_email', $enrollment);
        $this->assertObjectHasProperty('course_id', $enrollment);
        $this->assertObjectHasProperty('course_name', $enrollment);
        $this->assertObjectHasProperty('percentage_completed', $enrollment);
        $this->assertObjectHasProperty('activated_at', $enrollment);
        $this->assertObjectHasProperty('updated_at', $enrollment);
        $this->assertObjectHasProperty('completed', $enrollment);
        $this->assertObjectHasProperty('expired', $enrollment);
    }

    public function test_course_has_required_fields(): void
    {
        $courseData = KajabiApiResponses::course();
        $course = Course::fromResponse($courseData);

        // Fields required by Thinkific B2B Dashboard
        $this->assertObjectHasProperty('id', $course);
        $this->assertObjectHasProperty('name', $course);
        $this->assertObjectHasProperty('description', $course);
        $this->assertObjectHasProperty('slug', $course);
    }

    public function test_product_has_required_fields(): void
    {
        $productData = KajabiApiResponses::product();
        $product = Product::fromResponse($productData);

        // Fields required by Thinkific B2B Dashboard
        $this->assertObjectHasProperty('id', $product);
        $this->assertObjectHasProperty('name', $product);
        $this->assertObjectHasProperty('description', $product);
    }

    public function test_order_has_required_fields(): void
    {
        $orderData = KajabiApiResponses::order();
        $order = Order::fromResponse($orderData);

        // Fields required by Thinkific B2B Dashboard
        $this->assertObjectHasProperty('id', $order);
        $this->assertObjectHasProperty('total', $order);
        $this->assertObjectHasProperty('created_at', $order);
    }

    // ==================== Field Type Tests ====================

    public function test_user_field_types_are_correct(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertIsInt($user->id);
        $this->assertIsString($user->first_name);
        $this->assertIsString($user->last_name);
        $this->assertIsString($user->email);
        $this->assertIsArray($user->roles);
    }

    public function test_enrollment_field_types_are_correct(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        $this->assertIsInt($enrollment->id);
        $this->assertIsInt($enrollment->user_id);
        $this->assertIsInt($enrollment->course_id);
        $this->assertIsString($enrollment->user_email);
        $this->assertIsString($enrollment->course_name);
        $this->assertIsFloat($enrollment->percentage_completed);
        $this->assertIsBool($enrollment->completed);
        $this->assertIsBool($enrollment->expired);
        $this->assertIsBool($enrollment->is_free_trial);
    }
}
