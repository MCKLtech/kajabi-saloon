<?php

namespace Tests\Unit\DTOs;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\KajabiApiResponses;
use Tests\Fixtures\Json;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GrantOffer;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * Offer-grant enrollments must not invent progress or dates: a consumer has
 * to be able to tell "no progress" from "progress unknown", and a re-sync
 * must not move the start date.
 */
class EnrollmentProgressKnownTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private function fromOffer(?\DateTimeInterface $grantedAt = null): Enrollment
    {
        return Enrollment::fromKajabiOfferProduct(
            KajabiApiResponses::offer(),
            Json::data('products/course-a.json'),
            123,
            'a@example.com',
            'A B',
            grantedAt: $grantedAt
        );
    }

    public function test_offer_enrollment_has_placeholder_progress_and_no_invented_dates(): void
    {
        $enrollment = $this->fromOffer();

        $this->assertFalse($enrollment->progress_known);
        $this->assertSame(0.0, $enrollment->percentage_completed);
        $this->assertFalse($enrollment->completed);
        $this->assertNull($enrollment->started_at);
        $this->assertNull($enrollment->activated_at);
        $this->assertNull($enrollment->created_at);
        $this->assertInstanceOf(Carbon::class, $enrollment->updated_at, 'required by EnrollmentInterface');
    }

    public function test_repeated_reads_keep_the_same_start_date(): void
    {
        Carbon::setTestNow('2026-10-20T10:00:00Z');
        $first = $this->fromOffer();
        Carbon::setTestNow('2026-10-21T10:00:00Z');
        $second = $this->fromOffer();

        $this->assertSame($first->started_at, $second->started_at);
    }

    public function test_known_grant_time_fills_start_dates(): void
    {
        $enrollment = $this->fromOffer(new \DateTimeImmutable('2026-10-20T10:00:00Z'));

        $this->assertTrue(Carbon::parse('2026-10-20T10:00:00Z')->eq($enrollment->started_at));
        $this->assertTrue(Carbon::parse('2026-10-20T10:00:00Z')->eq($enrollment->activated_at));
        $this->assertNotSame($enrollment->started_at, $enrollment->activated_at, 'separate instances');
        $this->assertSame('2026-10-20T10:00:00.000000Z', $enrollment->created_at);
        $this->assertFalse($enrollment->progress_known);
    }

    public function test_purchase_enrollment_progress_is_unknown(): void
    {
        $this->assertFalse(Enrollment::fromKajabiPurchase(KajabiApiResponses::purchase())->progress_known);
    }

    public function test_create_records_the_grant_time(): void
    {
        Carbon::setTestNow('2026-10-20T10:00:00Z');

        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GrantOffer::class => MockResponse::make([], 204),
            GetOffer::class => MockResponse::make(['data' => Json::data('offers/offer-with-mixed-products.json')]),
            GetProducts::class => MockResponse::make(Json::catalog()),
        ]);
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', '111111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        $enrollment = $kajabi->enrollments->create(new CreateEnrollment(user_id: 123, course_id: 5001));

        $this->assertTrue(Carbon::parse('2026-10-20T10:00:00Z')->eq($enrollment->started_at));
        $this->assertFalse($enrollment->progress_known);
    }
}
