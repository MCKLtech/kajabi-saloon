<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\KajabiApiResponses;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOffers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithOffer;
use WooNinja\KajabiSaloon\Requests\Customers\GetCustomers;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
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

    private static function offer(int $id, string $title): array
    {
        return ['id' => (string) $id, 'type' => 'offers', 'attributes' => ['title' => $title, 'price_in_cents' => 0]];
    }

    /**
     * GET /contacts/{id}?include=offers as Kajabi sends it (offers in the relationship and in included)
     */
    private static function contactWithOffers(array $offers): MockResponse
    {
        $contact = KajabiApiResponses::contact();
        $contact['relationships']['offers'] = [
            'data' => array_map(fn($o) => ['id' => $o['id'], 'type' => 'offers'], $offers),
            'links' => ['self' => 'https://api.kajabi.com/v1/contacts/123/relationships/offers'],
        ];

        return MockResponse::make(['data' => $contact, 'included' => $offers, 'links' => ['self' => 'x']]);
    }

    public function test_enrollments_for_user_carry_offer_titles(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => self::contactWithOffers([self::offer(701, 'Leadership'), self::offer(702, 'Billing')]),
        ]);

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForUser(123)->items(), false);

        $this->assertSame([701, 702], array_map(fn(Enrollment $e) => $e->course_id, $enrollments));
        $this->assertSame(['Leadership', 'Billing'], array_map(fn(Enrollment $e) => $e->course_name, $enrollments));
        $this->assertSame('john.doe@example.com', $enrollments[0]->user_email);

        $request = $mockClient->getLastPendingRequest();
        $this->assertSame('/contacts/123', $request->getRequest()->resolveEndpoint());
        $this->assertSame('offers', $request->query()->get('include'));
    }

    public function test_offer_missing_from_included_still_becomes_an_enrollment(): void
    {
        $response = self::contactWithOffers([self::offer(701, 'Leadership')]);
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make((function () use ($response) {
                $body = $response->body()->all();
                $body['data']['relationships']['offers']['data'][] = ['id' => '799', 'type' => 'offers'];

                return $body;
            })()),
        ]);

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForUser(123)->items(), false);

        $this->assertSame([701, 799], array_map(fn(Enrollment $e) => $e->course_id, $enrollments));
        $this->assertSame('', $enrollments[1]->course_name);
    }

    public function test_enrollments_for_user_in_course_only_returns_that_course(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => self::contactWithOffers([self::offer(701, 'Leadership'), self::offer(702, 'Billing')]),
        ]);

        $enrollments = iterator_to_array(
            $this->kajabi($mockClient)->enrollments->enrollmentsForUserInCourse(123, 702)->items(),
            false
        );

        $this->assertSame([702], array_map(fn(Enrollment $e) => $e->course_id, $enrollments));
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

    public function test_enrollments_for_course_use_the_offer_title(): void
    {
        $mockClient = new MockClient([
            GetOffer::class => MockResponse::make(['data' => self::offer(701, 'Leadership')]),
            GetContactsWithOffer::class => MockResponse::make(['data' => [KajabiApiResponses::contact()], 'meta' => ['total' => 1], 'links' => []]),
        ]);

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForCourse(701)->items(), false);

        $this->assertSame('Leadership', $enrollments[0]->course_name);
        $this->assertSame(701, $enrollments[0]->course_id);
        $this->assertSame('701', $mockClient->getLastPendingRequest()->query()->get('filter[has_offer_id]'));
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
