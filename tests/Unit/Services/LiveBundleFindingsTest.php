<?php

namespace Tests\Unit\Services;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;
use Tests\Fixtures\Json;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\DeleteEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\UpdateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Offers\Offer;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOfferIds;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOffers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithOffer;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithProduct;
use WooNinja\KajabiSaloon\Requests\Offers\GrantOffer;
use WooNinja\KajabiSaloon\Requests\Offers\RevokeOffer;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\CreateBundleEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\UpdateBundleEnrollmentInterface;

/**
 * What the live API showed when the offer-as-bundle model was verified
 * (2026-10-27), replayed from redacted fixtures in tests/Fixtures/live/.
 * tests/Integration/LiveBundleTest.php runs the same paths against Kajabi.
 *
 * Offer A grants courses 1-4 plus an access group and a coaching program;
 * offer B grants course 2 (shared with A); offer C grants only an access
 * group; offer D grants course 5.
 */
class LiveBundleFindingsTest extends TestCase
{
    private const CONTACT = 9300000001;
    private const A = 9200000001;
    private const B = 9200000002;
    private const C = 9200000003;
    private const COURSES = [9100000001, 9100000002, 9100000003, 9100000004, 9100000005];

    private function kajabi(MockClient $mockClient): KajabiService
    {
        $kajabi = new KajabiService('id', 'secret', '9000000001');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        return $kajabi;
    }

    /**
     * As Kajabi answers: JSON:API content type
     */
    private static function live(string $fixture, int $status = 200): MockResponse
    {
        return MockResponse::make(Json::load("live/{$fixture}.json"), $status, ['Content-Type' => 'application/vnd.api+json; charset=utf-8']);
    }

    private static function catalog(): array
    {
        return [
            GetProducts::class => self::live('products'),
            'api.kajabi.com/v1/offers/' . self::A => self::live('offer-a'),
            'api.kajabi.com/v1/offers/' . self::B => self::live('offer-b'),
            'api.kajabi.com/v1/offers/' . self::C => self::live('offer-c'),
            'api.kajabi.com/v1/offers/9200000004' => self::live('offer-d'),
        ];
    }

    private static function contact(string $fixture): array
    {
        return [
            GetContact::class => MockResponse::make(['data' => Json::data("live/{$fixture}.json")]),
            GetContactOffers::class => self::live($fixture),
            GetContactOfferIds::class => self::live($fixture),
        ];
    }

    private static function bundleEnrollment(int $offerId): CreateBundleEnrollmentInterface&UpdateBundleEnrollmentInterface
    {
        return new class($offerId, self::CONTACT, null, null) implements CreateBundleEnrollmentInterface, UpdateBundleEnrollmentInterface {
            public function __construct(
                public int $productable_id,
                public int $user_id,
                public ?Carbon $activated_at,
                public ?Carbon $expiry_date,
            ) {}
        };
    }

    private static function productIds(array $enrollments): array
    {
        return array_map(fn(Enrollment $e) => $e->course_id, $enrollments);
    }

    // --- Catalog and offers ---

    public function test_only_course_type_products_count_as_courses(): void
    {
        // Live types: Course, AccessGroup, CoachingProgram
        $courses = $this->kajabi(new MockClient(self::catalog()))->products->courseProductResources();

        $this->assertSame(self::COURSES, array_keys($courses));
    }

    public function test_offer_grants_only_its_course_products_in_offer_order(): void
    {
        // Live: an offer of 17 products granted 12 courses
        $products = $this->kajabi(new MockClient(self::catalog()))->products;

        $this->assertSame(array_slice(self::COURSES, 0, 4), $products->courseProductIdsForOffer(self::A));
        $this->assertSame([], $products->courseProductIdsForOffer(self::C), 'an access-group-only offer grants no courses');
    }

    public function test_live_offer_without_timestamps_is_a_bundle(): void
    {
        $data = Json::data('live/offer-a.json');
        $this->assertArrayNotHasKey('created_at', $data['attributes'], 'live offers send no timestamps');

        $offer = Offer::fromKajabiOffer($data);
        $this->assertSame('Bundle', $offer->productable_type);
        $this->assertSame(self::A, $offer->productable_id);

        $bundle = $this->kajabi(new MockClient(self::catalog()))->bundles->get(self::A);
        $this->assertSame('Offer A', $bundle->name);
        $this->assertSame('Offer description', $bundle->description);
        $this->assertSame(array_slice(self::COURSES, 0, 4), $bundle->course_ids);
    }

    // --- Reads for a contact ---

    public function test_contact_enrollments_are_the_union_of_course_products_deduplicated(): void
    {
        $mockClient = new MockClient(self::contact('contact-with-offers-a-b-c') + self::catalog());

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForUser(self::CONTACT)->items(), false);

        // B's course is also in A; C grants no course
        $this->assertSame(array_slice(self::COURSES, 0, 4), self::productIds($enrollments));
        $this->assertSame(
            array_map(fn($p) => $p . ':' . self::CONTACT, array_slice(self::COURSES, 0, 4)),
            array_map(fn(Enrollment $e) => $e->id, $enrollments)
        );
        $this->assertSame('test.contact@example.com', $enrollments[0]->user_email);
    }

    public function test_held_offer_without_courses_is_no_bundle_enrollment(): void
    {
        $mockClient = new MockClient(self::contact('contact-with-offers-a-b-c') + self::catalog());
        $bundles = $this->kajabi($mockClient)->bundles;

        $this->assertSame([], $bundles->enrollmentsForUser(self::C, self::CONTACT));
        $this->assertFalse($bundles->isUserEnrolled(self::C, self::CONTACT));
        $this->assertSame([self::COURSES[1]], self::productIds($bundles->enrollmentsForUser(self::B, self::CONTACT)));
    }

    public function test_contact_offer_ids_from_the_live_shape(): void
    {
        $mockClient = new MockClient(self::contact('contact-with-offers-a-b-c'));

        $ids = $this->kajabi($mockClient)->connector()->send(new GetContactOfferIds(self::CONTACT))->dtoOrFail();

        $this->assertSame([self::A, self::B, self::C], $ids);
    }

    // --- Writes ---

    public function test_grant_answers_200_with_the_contacts_offers(): void
    {
        $mockClient = new MockClient([GrantOffer::class => self::live('grant-response')]);

        $response = $this->kajabi($mockClient)->bundles->createEnrollment(self::bundleEnrollment(self::A));

        $this->assertSame(200, $response->status(), 'not 204: Kajabi returns the relationship');
        $this->assertSame([['type' => 'offers', 'id' => (string) self::A]], $response->json('data'));
    }

    public function test_create_many_after_a_200_grant(): void
    {
        $mockClient = new MockClient(
            [GrantOffer::class => self::live('grant-response')] + self::contact('contact-without-offers') + self::catalog()
        );

        $enrollments = $this->kajabi($mockClient)->enrollments->createMany(new CreateEnrollment(user_id: self::CONTACT, course_id: self::A));

        $this->assertSame(array_slice(self::COURSES, 0, 4), self::productIds($enrollments));
        $this->assertNotNull($enrollments[0]->started_at);
    }

    public function test_update_grants_when_not_held_and_writes_nothing_when_held(): void
    {
        $notHeld = new MockClient(self::contact('contact-without-offers') + [GrantOffer::class => self::live('grant-response')]);
        $this->assertInstanceOf(
            GrantOffer::class,
            $this->kajabi($notHeld)->bundles->updateEnrollment(self::bundleEnrollment(self::A))->getRequest()
        );

        $held = new MockClient(self::contact('contact-with-offer-a'));
        $response = $this->kajabi($held)->bundles->updateEnrollment(self::bundleEnrollment(self::A));
        $this->assertInstanceOf(GetContactOfferIds::class, $response->getRequest());
        $held->assertNotSent(GrantOffer::class);
    }

    public function test_revoke_answers_200_with_the_remaining_offers(): void
    {
        $mockClient = new MockClient([RevokeOffer::class => self::live('revoke-response')]);

        $response = $this->kajabi($mockClient)->bundles->expireEnrollment(self::A, self::CONTACT);

        $this->assertSame(200, $response->status());
        $this->assertSame([], $response->json('data'));
    }

    public function test_expiring_an_offer_that_is_not_held_succeeds(): void
    {
        // Live: Kajabi answers 400 "Cannot remove relationships"; expiry treats it as done once confirmed
        $mockClient = new MockClient([RevokeOffer::class => self::live('revoke-not-held-response', 400)] + self::contact('contact-without-offers'));
        $kajabi = $this->kajabi($mockClient);

        foreach ([
            fn() => $kajabi->bundles->expireEnrollment(self::A, self::CONTACT),
            fn() => $kajabi->enrollments->expire(new DeleteEnrollment(enrollment_id: 0, user_id: self::CONTACT, course_id: self::A)),
            fn() => $kajabi->enrollments->unenroll(self::CONTACT, self::A),
            fn() => $kajabi->enrollments->update(new UpdateEnrollment(0, null, Carbon::now()->subDay(), self::CONTACT, self::A)),
        ] as $expire) {
            $response = $expire();
            $this->assertTrue($response->successful());
            $this->assertInstanceOf(GetContactOfferIds::class, $response->getRequest(), 'the confirming read');
        }
    }

    public function test_cannot_remove_while_the_offer_is_still_held_throws(): void
    {
        $mockClient = new MockClient([RevokeOffer::class => self::live('revoke-not-held-response', 400)] + self::contact('contact-with-offer-a'));

        try {
            $this->kajabi($mockClient)->bundles->expireEnrollment(self::A, self::CONTACT);
            $this->fail('expected a RequestException');
        } catch (RequestException $e) {
            $this->assertSame(400, $e->getResponse()->status());
            $this->assertSame('Cannot remove relationships', $e->getResponse()->json('errors.0.detail'));
        }
    }

    /**
     * @return array<string, array{int, array}>
     */
    public static function genuineRevokeErrors(): array
    {
        return [
            'other 400' => [400, ['errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid offer']]]],
            'unknown contact (403)' => [403, ['errors' => [['status' => '403', 'title' => 'Forbidden']]]],
            'not found' => [404, ['errors' => [['status' => '404', 'title' => 'Not Found']]]],
            'rate limited' => [429, ['errors' => [['status' => '429']]]],
            'server error' => [500, ['errors' => [['status' => '500']]]],
        ];
    }

    /**
     * @dataProvider genuineRevokeErrors
     */
    public function test_genuine_revoke_errors_still_throw(int $status, array $body): void
    {
        $mockClient = new MockClient([RevokeOffer::class => MockResponse::make($body, $status)] + self::contact('contact-without-offers'));
        $kajabi = $this->kajabi($mockClient);

        foreach ([
            fn() => $kajabi->bundles->expireEnrollment(self::A, self::CONTACT),
            fn() => $kajabi->enrollments->expire(new DeleteEnrollment(enrollment_id: 0, user_id: self::CONTACT, course_id: self::A)),
        ] as $expire) {
            try {
                $expire();
                $this->fail("expected HTTP {$status} to throw");
            } catch (RequestException $e) {
                $this->assertSame($status, $e->getResponse()->status());
            } catch (RateLimitReachedException) {
                $this->assertSame(429, $status, 'the connector reports 429s as rate-limit exceptions');
            }
        }

        $mockClient->assertNotSent(GetContactOfferIds::class);
    }

    public function test_revoking_one_offer_keeps_a_course_another_offer_grants(): void
    {
        // After revoking B the contact holds only A, which also grants course 2
        $mockClient = new MockClient(self::contact('contact-with-offer-a') + self::catalog());
        $enrollments = $this->kajabi($mockClient)->enrollments;

        $this->assertTrue($enrollments->isUserEnrolledInCourse(self::CONTACT, self::COURSES[1]));
        $this->assertFalse($enrollments->isUserEnrolledInCourse(self::CONTACT, self::COURSES[4]), 'course 5 is only in offer D');
    }

    public function test_update_with_a_past_expiry_revokes_and_otherwise_grants(): void
    {
        $mockClient = new MockClient([RevokeOffer::class => self::live('revoke-response'), GrantOffer::class => self::live('grant-response')]);
        $enrollments = $this->kajabi($mockClient)->enrollments;

        $past = $enrollments->update(new UpdateEnrollment(0, null, Carbon::now()->subDay(), self::CONTACT, self::A));
        $open = $enrollments->update(new UpdateEnrollment(0, null, null, self::CONTACT, self::A));

        $this->assertInstanceOf(RevokeOffer::class, $past->getRequest());
        $this->assertInstanceOf(GrantOffer::class, $open->getRequest());
    }

    // --- Contacts ---

    public function test_a_new_contact_is_not_yet_searchable_by_email_but_works_by_id(): void
    {
        // Live: filter[search] missed a contact created minutes earlier
        $mockClient = new MockClient([
            GetContacts::class => self::live('contacts-search-empty'),
            RevokeOffer::class => self::live('revoke-response'),
        ]);
        $enrollments = $this->kajabi($mockClient)->enrollments;

        try {
            $enrollments->unenroll('test.contact@example.com', self::A);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Contact not found for email', $e->getMessage());
        }
        $mockClient->assertNotSent(RevokeOffer::class);

        $this->assertSame(200, $enrollments->unenroll(self::CONTACT, self::A)->status());
        $this->assertSame(1, count(array_filter(
            $mockClient->getRecordedResponses(),
            fn($response) => $response->getRequest() instanceof GetContacts
        )), 'by id needs no search');
    }

    public function test_membership_filters_are_ones_kajabi_supports(): void
    {
        // Live: has_offer_id and has_product_id both narrow /contacts (unsupported filters are ignored silently)
        $mockClient = new MockClient([
            GetContactsWithOffer::class => MockResponse::make(['data' => [], 'meta' => ['total' => 0], 'links' => []]),
            GetContactsWithProduct::class => MockResponse::make(['data' => [], 'meta' => ['total' => 0], 'links' => []]),
        ] + self::catalog());
        $kajabi = $this->kajabi($mockClient);
        $kajabi->connector()->setStrictFilters();

        iterator_to_array($kajabi->bundles->enrollments(self::A)->items(), false);
        $this->assertSame((string) self::A, $mockClient->getLastPendingRequest()->query()->get('filter[has_offer_id]'));

        iterator_to_array($kajabi->enrollments->enrollmentsForCourse(self::COURSES[0])->items(), false);
        $this->assertSame((string) self::COURSES[0], $mockClient->getLastPendingRequest()->query()->get('filter[has_product_id]'));
    }
}
