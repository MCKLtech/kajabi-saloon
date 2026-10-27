<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\Json;
use Tests\Fixtures\KajabiApiResponses;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOffers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithProduct;
use WooNinja\KajabiSaloon\Requests\Customers\GetCustomers;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Requests\Purchases\GetPurchases;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * Read paths found wrong in the live end-to-end review
 */
class EnrollmentLookupTest extends TestCase
{
    private function kajabi(MockClient $mockClient): KajabiService
    {
        $kajabi = new KajabiService('id', 'secret', '111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        return $kajabi;
    }

    private static function offer(int $id, string $title, array $productIds): array
    {
        return [
            'id' => (string) $id,
            'type' => 'offers',
            'attributes' => ['title' => $title, 'price_in_cents' => 0],
            'relationships' => ['products' => ['data' => array_map(fn($p) => ['id' => (string) $p, 'type' => 'products'], $productIds)]],
        ];
    }

    public function test_enrollments_for_user_carry_product_titles(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make(Json::load('contacts/contact-with-two-offers.json')),
            GetProducts::class => MockResponse::make(Json::catalog()),
            'api.kajabi.com/v1/offers/5001' => MockResponse::make(Json::load('offers/offer-with-mixed-products.json')),
            'api.kajabi.com/v1/offers/5002' => MockResponse::make(['data' => self::offer(5002, 'Course A Only', [2001])]),
        ]);

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForUser(123)->items(), false);

        $this->assertSame([2001, 2002], array_map(fn(Enrollment $e) => $e->course_id, $enrollments));
        $this->assertSame(['Course A', 'Course B'], array_map(fn(Enrollment $e) => $e->course_name, $enrollments));
        $this->assertSame('john.doe@example.com', $enrollments[0]->user_email);

        $contactRequest = $mockClient->getRecordedResponses()[1]->getPendingRequest();
        $this->assertSame('/contacts/123', $contactRequest->getRequest()->resolveEndpoint());
        $this->assertSame('offers', $contactRequest->query()->get('include'));
    }

    public function test_offer_missing_from_included_still_expands(): void
    {
        $body = Json::load('contacts/contact-with-two-offers.json');
        $body['included'] = [];

        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make($body),
            GetProducts::class => MockResponse::make(Json::catalog()),
            'api.kajabi.com/v1/offers/5001' => MockResponse::make(Json::load('offers/offer-with-mixed-products.json')),
            'api.kajabi.com/v1/offers/5002' => MockResponse::make(['data' => self::offer(5002, 'Course A Only', [2001])]),
        ]);

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForUser(123)->items(), false);

        $this->assertSame(['Course A', 'Course B'], array_map(fn(Enrollment $e) => $e->course_name, $enrollments));
    }

    public function test_enrollments_for_user_in_course_only_returns_that_product(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make(Json::load('contacts/contact-with-two-offers.json')),
            GetProducts::class => MockResponse::make(Json::catalog()),
            'api.kajabi.com/v1/offers/5001' => MockResponse::make(Json::load('offers/offer-with-mixed-products.json')),
            'api.kajabi.com/v1/offers/5002' => MockResponse::make(['data' => self::offer(5002, 'Course A Only', [2001])]),
        ]);
        $enrollments = $this->kajabi($mockClient)->enrollments;

        $inB = iterator_to_array($enrollments->enrollmentsForUserInCourse(123, 2002)->items(), false);

        $this->assertSame(['2002:123'], array_map(fn(Enrollment $e) => $e->id, $inB));
        $this->assertTrue($enrollments->isUserEnrolledInCourse(123, 2001));
        $this->assertFalse($enrollments->isUserEnrolledInCourse(123, 2003), 'access groups are not courses');
        $this->assertFalse($enrollments->isUserEnrolledInCourse(123, 5001), 'an offer id is not a course id');
    }

    public function test_enrollments_for_unknown_email_are_empty_without_unsupported_filters(): void
    {
        $mockClient = new MockClient([
            GetContacts::class => MockResponse::make(['data' => [], 'meta' => ['total' => 0]]),
            GetPurchases::class => MockResponse::make(['data' => [], 'links' => []]),
        ]);
        $kajabi = $this->kajabi($mockClient);
        $kajabi->connector()->setStrictFilters();

        $enrollments = iterator_to_array($kajabi->enrollments->enrollmentsForUser('nobody@example.com')->items(), false);

        $this->assertSame([], $enrollments);
        $this->assertSame('0', (string) $mockClient->getLastPendingRequest()->query()->get('filter[customer_id]'));
    }

    public function test_enrollments_for_course_are_contacts_with_the_product(): void
    {
        $mockClient = new MockClient([
            GetProducts::class => MockResponse::make(Json::catalog()),
            GetContactsWithProduct::class => MockResponse::make(['data' => [KajabiApiResponses::contact()], 'meta' => ['total' => 1], 'links' => []]),
        ]);

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForCourse(2002)->items(), false);

        $this->assertCount(1, $enrollments);
        $this->assertSame('Course B', $enrollments[0]->course_name);
        $this->assertSame(2002, $enrollments[0]->course_id);
        $this->assertSame('2002:123', $enrollments[0]->id);
        $this->assertSame('2002', $mockClient->getLastPendingRequest()->query()->get('filter[has_product_id]'));
    }

    public function test_find_by_email_picks_the_exact_match_among_partial_hits(): void
    {
        $partial = KajabiApiResponses::contact();
        $partial['id'] = '9';
        $partial['attributes']['email'] = 'big.john.doe@example.com';
        $exact = KajabiApiResponses::contact();
        $exact['attributes']['email'] = 'John.Doe@example.com';

        $mockClient = new MockClient([GetContacts::class => MockResponse::make(['data' => [$partial, $exact], 'meta' => ['total' => 2]])]);

        $user = $this->kajabi($mockClient)->users->findByEmail('john.doe@example.com');

        $this->assertSame(123, $user->id);
        $this->assertSame(25, (int) $mockClient->getLastPendingRequest()->query()->get('page[size]'));
    }

    public function test_email_filter_on_enrollments_needs_an_exact_customer_match(): void
    {
        $customer = fn(int $id, string $email) => ['id' => (string) $id, 'type' => 'customers', 'attributes' => ['email' => $email]];
        $mockClient = new MockClient([
            GetCustomers::class => MockResponse::make(['data' => [$customer(9, 'big.ann@example.com'), $customer(5, 'Ann@example.com')], 'meta' => ['total' => 2]]),
            GetPurchases::class => MockResponse::make(['data' => [], 'links' => []]),
        ]);

        iterator_to_array($this->kajabi($mockClient)->enrollments->enrollments(['query[email]' => 'ann@example.com'])->items(), false);

        $this->assertSame(5, (int) $mockClient->getLastPendingRequest()->query()->get('filter[customer_id]'), 'not the partial match ranked first');
    }

    public function test_email_filter_with_only_partial_matches_throws(): void
    {
        $mockClient = new MockClient([
            GetCustomers::class => MockResponse::make(['data' => [['id' => '9', 'type' => 'customers', 'attributes' => ['email' => 'big.ann@example.com']]], 'meta' => ['total' => 1]]),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->kajabi($mockClient)->enrollments->enrollments(['query[email]' => 'ann@example.com']);
    }

    public function test_find_returns_null_only_when_the_contact_does_not_exist(): void
    {
        $notFound = new MockClient([GetContact::class => MockResponse::make(['errors' => [['status' => '404']]], 404)]);
        $this->assertNull($this->kajabi($notFound)->users->find(1));

        // Kajabi's answer for an id that isn't on the account (verified live)
        $notOnAccount = new MockClient([GetContact::class => MockResponse::make(['errors' => [['status' => '403', 'title' => 'Forbidden']]], 403)]);
        $this->assertNull($this->kajabi($notOnAccount)->users->find(1));

        $serverError = new MockClient([GetContact::class => MockResponse::make(['errors' => [['status' => '500']]], 500)]);
        $this->expectException(RequestException::class);
        $this->kajabi($serverError)->users->find(1);
    }
}
