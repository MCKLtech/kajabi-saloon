<?php

namespace Tests\Unit\Services;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\KajabiApiResponses;
use Tests\Fixtures\MemberActivityPayloads;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\Exceptions\FeatureNotEnabledException;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\CourseProgress\GetCourseProgress;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Services\EnrollmentService;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\EnrollmentInterface;

class EnrollmentServiceProgressTest extends TestCase
{
    private function serviceWithMock(MockClient $mockClient): EnrollmentService
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', '111111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));

        return new EnrollmentService($kajabi);
    }

    private function offerWithProducts(array $productIds): array
    {
        $offer = KajabiApiResponses::offer();
        $offer['relationships']['products']['data'] = array_map(
            fn($id) => ['id' => (string) $id, 'type' => 'products'],
            $productIds
        );

        return $offer;
    }

    private function enrollment(): Enrollment
    {
        return Enrollment::fromKajabiOffer(KajabiApiResponses::offer(), 123, 'john.doe@example.com', 'John Doe');
    }

    private function row(array $fixture, int $productId): CourseProgress
    {
        return CourseProgress::fromKajabiCourseProgress($fixture, $productId);
    }

    // --- Enrollment::withCourseProgress (pure aggregation) ---

    public function test_with_course_progress_single_complete_course(): void
    {
        $original = $this->enrollment();
        $enrollment = $original->withCourseProgress([$this->row(MemberActivityPayloads::progressRowComplete(), 2000001)]);

        $this->assertNotSame($original, $enrollment, 'returns a copy');
        $this->assertSame(0.0, $original->percentage_completed, 'original untouched');

        $this->assertSame(1.0, $enrollment->percentage_completed);
        $this->assertTrue($enrollment->completed);
        $this->assertTrue(Carbon::parse('2026-10-20T19:04:11Z')->eq($enrollment->started_at));
        $this->assertTrue(Carbon::parse('2026-10-20T19:20:31Z')->eq($enrollment->completed_at));
        $this->assertTrue(Carbon::parse('2026-10-20T19:20:31.845Z')->eq($enrollment->updated_at));
        $this->assertSame('completed', $enrollment->status);
    }

    public function test_with_course_progress_partial_course(): void
    {
        $enrollment = $this->enrollment()->withCourseProgress([$this->row(MemberActivityPayloads::progressRowPartial(), 1)]);

        $this->assertSame(0.5, $enrollment->percentage_completed);
        $this->assertFalse($enrollment->completed);
        $this->assertNull($enrollment->completed_at);
        $this->assertSame('active', $enrollment->status);
    }

    public function test_with_course_progress_aggregates_by_lesson_count_across_products(): void
    {
        // 2/2 + 1/2 + 0/3 = 3/7
        $enrollment = $this->enrollment()->withCourseProgress([
            $this->row(MemberActivityPayloads::progressRowComplete(), 1),
            $this->row(MemberActivityPayloads::progressRowPartial(), 2),
            $this->row(MemberActivityPayloads::progressRowEmpty(), 3),
        ]);

        $this->assertSame(round(3 / 7, 4), $enrollment->percentage_completed);
        $this->assertFalse($enrollment->completed, 'only complete when every course is');
        $this->assertNull($enrollment->completed_at);
        $this->assertTrue(Carbon::parse('2026-10-20T19:04:11Z')->eq($enrollment->started_at), 'earliest lesson completion');
        $this->assertTrue(Carbon::parse('2026-10-20T19:41:07.310Z')->eq($enrollment->updated_at), 'latest row update');
    }

    public function test_with_course_progress_no_completed_lessons_keeps_started_at(): void
    {
        $original = $this->enrollment();
        $enrollment = $original->withCourseProgress([$this->row(MemberActivityPayloads::progressRowEmpty(), 1)]);

        $this->assertSame(0.0, $enrollment->percentage_completed);
        $this->assertEquals($original->started_at, $enrollment->started_at);
    }

    public function test_with_course_progress_without_rows_is_unchanged_copy(): void
    {
        $original = $this->enrollment();
        $enrollment = $original->withCourseProgress([]);

        $this->assertNotSame($original, $enrollment);
        $this->assertEquals($original, $enrollment);
    }

    public function test_with_course_progress_zero_published_lessons(): void
    {
        $fixture = MemberActivityPayloads::progressRowEmpty();
        $fixture['attributes']['total_lessons'] = 0;
        $fixture['attributes']['lessons'] = [];

        $enrollment = $this->enrollment()->withCourseProgress([$this->row($fixture, 1)]);

        $this->assertSame(0.0, $enrollment->percentage_completed);
        $this->assertFalse($enrollment->completed);
    }

    // --- EnrollmentService::progress / withProgress ---

    public function test_progress_resolves_customer_and_offer_products(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]), // customer 456
            GetOffer::class => MockResponse::make(['data' => $this->offerWithProducts([2000001])]),
            GetCourseProgress::class => MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowComplete()])),
        ]);

        $rows = $this->serviceWithMock($mockClient)->progress(123, 101);

        $this->assertCount(1, $rows);
        $this->assertSame(2000001, $rows[0]->product_id);

        $query = $mockClient->getLastPendingRequest()->query()->all();
        $this->assertSame(2000001, $query['filter[product_id]']);
        $this->assertSame(456, $query['filter[customer_id]']);
    }

    public function test_progress_skips_unsupported_products_and_missing_records(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['data' => KajabiApiResponses::contact()]),
            MockResponse::make(['data' => $this->offerWithProducts([1, 2, 3])]),
            MockResponse::make(['errors' => [['status' => '404']]], 404), // product 1: not a course / cohort
            MockResponse::make(MemberActivityPayloads::progressPage([])), // product 2: no progress record
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowPartial()])),
        ]);

        $rows = $this->serviceWithMock($mockClient)->progress(123, 101);

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->product_id);
        $mockClient->assertSentCount(5);
    }

    public function test_progress_is_empty_for_contact_without_customer(): void
    {
        $contact = KajabiApiResponses::contact();
        unset($contact['relationships']['customer']);

        $mockClient = new MockClient([MockResponse::make(['data' => $contact])]);

        $this->assertSame([], $this->serviceWithMock($mockClient)->progress(123, 101));
        $mockClient->assertSentCount(1);
    }

    public function test_progress_by_email(): void
    {
        $mockClient = new MockClient([
            GetContacts::class => MockResponse::make(['data' => [KajabiApiResponses::contact()]]),
            GetOffer::class => MockResponse::make(['data' => $this->offerWithProducts([2000001])]),
            GetCourseProgress::class => MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowComplete()])),
        ]);

        $this->assertCount(1, $this->serviceWithMock($mockClient)->progress('john.doe@example.com', 101));
    }

    public function test_progress_propagates_feature_not_enabled(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['data' => KajabiApiResponses::contact()]),
            MockResponse::make(['data' => $this->offerWithProducts([2000001])]),
            MockResponse::make([], 423),
        ]);

        $this->expectException(FeatureNotEnabledException::class);

        $this->serviceWithMock($mockClient)->progress(123, 101);
    }

    public function test_progress_propagates_non_404_errors(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['data' => KajabiApiResponses::contact()]),
            MockResponse::make(['data' => $this->offerWithProducts([2000001])]),
            MockResponse::make([], 403),
        ]);

        $this->expectException(\Saloon\Exceptions\Request\RequestException::class);

        $this->serviceWithMock($mockClient)->progress(123, 101);
    }

    public function test_with_progress_enriches_enrollment(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetOffer::class => MockResponse::make(['data' => $this->offerWithProducts([2000001])]),
            GetCourseProgress::class => MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowPartial()])),
        ]);

        $enrollment = $this->serviceWithMock($mockClient)->withProgress($this->enrollment());

        $this->assertInstanceOf(Enrollment::class, $enrollment);
        $this->assertSame(0.5, $enrollment->percentage_completed);

        // user_id (contact 123) and course_id (offer 101) drove the lookups
        $this->assertStringEndsWith('/offers/101', $mockClient->getRecordedResponses()[1]->getPendingRequest()->getUrl());
    }

    public function test_with_progress_passes_through_foreign_enrollment(): void
    {
        $foreign = $this->createStub(EnrollmentInterface::class);
        $mockClient = new MockClient([]);

        $this->assertSame($foreign, $this->serviceWithMock($mockClient)->withProgress($foreign));
        $this->assertNull($mockClient->getLastPendingRequest());
    }
}
