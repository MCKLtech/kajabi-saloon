<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\KajabiApiResponses;
use Tests\Fixtures\MemberActivityPayloads;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Exceptions\FeatureNotEnabledException;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\CourseProgress\GetCourseProgress;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Services\EnrollmentService;
use WooNinja\KajabiSaloon\Services\KajabiService;

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

    // --- EnrollmentService::progress ---

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


}
