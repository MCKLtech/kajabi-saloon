<?php

namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\EnrollmentInterface;
use Tests\Fixtures\KajabiApiResponses;
use Carbon\Carbon;

class EnrollmentTest extends TestCase
{
    public function test_implements_enrollment_interface(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        $this->assertInstanceOf(EnrollmentInterface::class, $enrollment);
    }

    public function test_creates_enrollment_from_kajabi_purchase(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        $this->assertEquals(789, $enrollment->id);
        $this->assertEquals('john.doe@example.com', $enrollment->user_email);
        $this->assertEquals('John Doe', $enrollment->user_name);
        $this->assertEquals('Introduction to Marketing', $enrollment->course_name);
    }

    public function test_extracts_ids_from_relationships(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        // User ID extracted from customer relationship
        $this->assertEquals(456, $enrollment->user_id);

        // Course ID extracted from product relationship
        $this->assertEquals(202, $enrollment->course_id);
    }

    public function test_percentage_completed_defaults_to_zero(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        // Kajabi doesn't track completion percentage the same way as Thinkific
        $this->assertEquals(0.0, $enrollment->percentage_completed);
    }

    public function test_completed_status_from_kajabi_status(): void
    {
        $purchaseData = KajabiApiResponses::purchase();

        // Active purchase - not completed
        $purchaseData['attributes']['status'] = 'active';
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);
        $this->assertFalse($enrollment->completed);

        // Completed purchase
        $purchaseData['attributes']['status'] = 'completed';
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);
        $this->assertTrue($enrollment->completed);
    }

    public function test_expired_status_from_kajabi_status(): void
    {
        $purchaseData = KajabiApiResponses::purchase();

        // Active - not expired
        $purchaseData['attributes']['status'] = 'active';
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);
        $this->assertFalse($enrollment->expired);

        // Expired status
        $purchaseData['attributes']['status'] = 'expired';
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);
        $this->assertTrue($enrollment->expired);

        // Cancelled status
        $purchaseData['attributes']['status'] = 'cancelled';
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);
        $this->assertTrue($enrollment->expired);
    }

    public function test_free_trial_detection(): void
    {
        $purchaseData = KajabiApiResponses::purchase();

        // Paid purchase
        $purchaseData['attributes']['amount_in_cents'] = 9900;
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);
        $this->assertFalse($enrollment->is_free_trial);

        // Free purchase
        $purchaseData['attributes']['amount_in_cents'] = 0;
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);
        $this->assertTrue($enrollment->is_free_trial);
    }

    public function test_timestamps_are_carbon_instances(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        $this->assertInstanceOf(Carbon::class, $enrollment->activated_at);
        $this->assertInstanceOf(Carbon::class, $enrollment->started_at);
        $this->assertInstanceOf(Carbon::class, $enrollment->updated_at);
        $this->assertNull($enrollment->completed_at); // Not completed yet
    }

    public function test_started_at_fallback_to_created_at(): void
    {
        $purchaseData = KajabiApiResponses::purchase();

        // Remove started_at
        unset($purchaseData['attributes']['started_at']);
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        // Should fallback to created_at
        $this->assertInstanceOf(Carbon::class, $enrollment->started_at);
        $this->assertEquals('2024-01-15T10:30:00Z', $enrollment->started_at->toIso8601String());
    }

    public function test_activated_at_fallback_to_created_at(): void
    {
        $purchaseData = KajabiApiResponses::purchase();

        // Remove activated_at
        unset($purchaseData['attributes']['activated_at']);
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        // Should fallback to created_at
        $this->assertInstanceOf(Carbon::class, $enrollment->activated_at);
    }

    public function test_expiry_date_is_parsed(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        $this->assertInstanceOf(Carbon::class, $enrollment->expiry_date);
        $this->assertEquals('2025-01-15', $enrollment->expiry_date->format('Y-m-d'));
    }


    public function test_credential_fields_are_null(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        // Kajabi handles credentials differently
        $this->assertNull($enrollment->credential_id);
        $this->assertNull($enrollment->certificate_url);
        $this->assertNull($enrollment->certificate_expiry_date);

    }

    public function test_kajabi_specific_status_field(): void
    {
        $purchaseData = KajabiApiResponses::purchase();
        $enrollment = Enrollment::fromKajabiPurchase($purchaseData);

        // Kajabi-specific field preserved
        $this->assertEquals('active', $enrollment->status);
    }

    public function test_get_enrollment_id_generates_unique_id(): void
    {
        $id1 = Enrollment::getEnrollmentId(100, 200);
        $id2 = Enrollment::getEnrollmentId(100, 201);
        $id3 = Enrollment::getEnrollmentId(101, 200);

        // All IDs should be unique
        $this->assertNotEquals($id1, $id2);
        $this->assertNotEquals($id1, $id3);
        $this->assertNotEquals($id2, $id3);
    }

    public function test_get_enrollment_id_is_deterministic(): void
    {
        $id1 = Enrollment::getEnrollmentId(12345, 67890);
        $id2 = Enrollment::getEnrollmentId(12345, 67890);

        // Same inputs should always produce same output
        $this->assertEquals($id1, $id2);
    }

    public function test_get_enrollment_id_handles_large_kajabi_ids(): void
    {
        // Real-world Kajabi IDs that caused overflow
        $offerId = 2150553057;
        $contactId = 2670937203;

        $enrollmentId = Enrollment::getEnrollmentId($offerId, $contactId);

        // Should NOT be PHP_INT_MAX (the overflow value)
        $this->assertNotEquals(PHP_INT_MAX, $enrollmentId);

        // Should be a valid string representation of the concatenated IDs
        $this->assertEquals('21505530572670937203', $enrollmentId);
    }

    public function test_get_enrollment_id_returns_string_type(): void
    {
        $enrollmentId = Enrollment::getEnrollmentId(2150553057, 2670937203);

        // Must be string to avoid integer overflow
        $this->assertIsString($enrollmentId);
    }

    public function test_enrollment_from_purchase_uses_string_id_for_large_ids(): void
    {
        $purchaseData = KajabiApiResponses::purchase();

        // Use large IDs that would overflow as integers
        $purchaseData['relationships']['offer']['data']['id'] = '2150553057';
        $purchaseData['relationships']['customer']['data']['id'] = '2670937203';

        // Need to add customer to included map for contact_id extraction
        $includedMap = [
            'customers:2670937203' => [
                'id' => '2670937203',
                'type' => 'customers',
                'attributes' => [
                    'email' => 'test@example.com',
                    'name' => 'Test User',
                ],
                'relationships' => [
                    'contact' => [
                        'data' => ['id' => '2670937203', 'type' => 'contacts']
                    ]
                ]
            ],
            'offers:2150553057' => [
                'id' => '2150553057',
                'type' => 'offers',
                'attributes' => [
                    'title' => 'Test Offer',
                ]
            ]
        ];

        $enrollment = Enrollment::fromKajabiPurchase($purchaseData, $includedMap);

        // The enrollment ID should be a string, not truncated to PHP_INT_MAX
        $this->assertEquals('21505530572670937203', $enrollment->id);
        $this->assertNotEquals(PHP_INT_MAX, $enrollment->id);
    }

    public function test_enrollment_from_offer_uses_string_id_for_large_ids(): void
    {
        $offerData = KajabiApiResponses::offer();
        $offerData['id'] = 2150553057;

        $customerId = 2670937203;
        $customerEmail = 'test@example.com';
        $customerName = 'Test User';

        $enrollment = Enrollment::fromKajabiOffer(
            $offerData,
            $customerId,
            $customerEmail,
            $customerName
        );

        // The enrollment ID should be a string, not truncated to PHP_INT_MAX
        $this->assertEquals('21505530572670937203', $enrollment->id);
        $this->assertNotEquals(PHP_INT_MAX, $enrollment->id);
    }

    public function test_multiple_large_id_enrollments_are_unique(): void
    {
        // Simulate multiple enrollments for same user with different offers
        $contactId = 2670937203;
        $offerIds = [2150553057, 2150553059, 2150540451, 2150527498];

        $enrollmentIds = [];
        foreach ($offerIds as $offerId) {
            $enrollmentIds[] = Enrollment::getEnrollmentId($offerId, $contactId);
        }

        // All enrollment IDs should be unique
        $this->assertCount(4, array_unique($enrollmentIds));

        // None should be PHP_INT_MAX
        foreach ($enrollmentIds as $id) {
            $this->assertNotEquals(PHP_INT_MAX, $id);
        }
    }
}
