<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\Json;
use Tests\Fixtures\KajabiApiResponses;
use WooNinja\KajabiSaloon\Cache\ArrayCache;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Bundles\Bundle;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\DeleteEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\ReadEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Offers\Offer;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOfferIds;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOffers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GrantOffer;
use WooNinja\KajabiSaloon\Requests\Offers\RevokeOffer;
use WooNinja\KajabiSaloon\Requests\Products\GetCourseProducts;
use WooNinja\KajabiSaloon\Requests\Products\GetProduct;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\CreateBundleEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\UpdateBundleEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\Services\BundleServiceInterface;

/**
 * An offer is a bundle; a course product is a course; holding an offer is an
 * enrollment in each course product it grants.
 *
 * Fixtures: offer 5001 grants course A (2001), course B (2002) and access
 * group C (2003); offer 5002 grants course A only; contact 123 holds both.
 */
class OfferBundleTest extends TestCase
{
    private function kajabi(MockClient $mockClient, string $siteId = '111'): KajabiService
    {
        $kajabi = new KajabiService('id', 'secret', $siteId);
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        return $kajabi;
    }

    /**
     * The contact holding only offer 5001
     */
    private static function contactWithMixedOffer(): array
    {
        $body = Json::load('contacts/contact-with-two-offers.json');
        $body['data']['relationships']['offers']['data'] = [['id' => '5001', 'type' => 'offers']];
        $body['included'] = [Json::data('offers/offer-with-mixed-products.json')];

        return $body;
    }

    private static function catalogMocks(): array
    {
        return [
            GetProducts::class => MockResponse::make(Json::catalog()),
            'api.kajabi.com/v1/offers/5001' => MockResponse::make(Json::load('offers/offer-with-mixed-products.json')),
            'api.kajabi.com/v1/offers/5002' => MockResponse::make(['data' => [
                'id' => '5002',
                'type' => 'offers',
                'attributes' => ['title' => 'Course A Only'],
                'relationships' => ['products' => ['data' => [['id' => '2001', 'type' => 'products']]]],
            ]]),
        ];
    }

    private static function sent(MockClient $mockClient, string $requestClass): int
    {
        return count(array_filter(
            $mockClient->getRecordedResponses(),
            fn($response) => $response->getRequest() instanceof $requestClass
        ));
    }

    private static function bundleEnrollment(int $offerId, int $contactId): CreateBundleEnrollmentInterface&UpdateBundleEnrollmentInterface
    {
        return new class($offerId, $contactId, null, null) implements CreateBundleEnrollmentInterface, UpdateBundleEnrollmentInterface {
            public function __construct(
                public int $productable_id,
                public int $user_id,
                public ?\Carbon\Carbon $activated_at,
                public ?\Carbon\Carbon $expiry_date,
            ) {}
        };
    }

    // --- Acceptance 1, 2: one enrollment per course product, de-duplicated ---

    public function test_held_offer_yields_one_enrollment_per_course_product(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make(self::contactWithMixedOffer()),
        ] + self::catalogMocks());

        $enrollments = $this->kajabi($mockClient)->bundles->enrollmentsForUser(5001, 123);

        $this->assertSame(['2001:123', '2002:123'], array_map(fn(Enrollment $e) => $e->id, $enrollments));
        $this->assertSame([2001, 2002], array_map(fn(Enrollment $e) => $e->course_id, $enrollments));
        $this->assertSame([false, false], array_map(fn(Enrollment $e) => $e->expired, $enrollments));
        $this->assertSame(123, $enrollments[0]->user_id);
    }

    public function test_offers_sharing_a_product_yield_one_enrollment_for_it(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make(Json::load('contacts/contact-with-two-offers.json')),
        ] + self::catalogMocks());

        $enrollments = iterator_to_array($this->kajabi($mockClient)->enrollments->enrollmentsForUser(123)->items(), false);

        $this->assertSame(['2001:123', '2002:123'], array_map(fn(Enrollment $e) => $e->id, $enrollments));
    }

    public function test_offer_not_held_yields_no_enrollments(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make(self::contactWithMixedOffer()),
        ] + self::catalogMocks());
        $bundles = $this->kajabi($mockClient)->bundles;

        $this->assertSame([], $bundles->enrollmentsForUser(5002, 123));
        $this->assertFalse($bundles->isUserEnrolled(5002, 123));
        $this->assertTrue($bundles->isUserEnrolled(5001, 123));
        $this->assertTrue($bundles->hasActiveEnrollment(5001, 123));
        $this->assertTrue($bundles->hasAnyEnrollment(5001, 123));
    }

    public function test_unknown_email_has_no_bundle_enrollments(): void
    {
        $mockClient = new MockClient([GetContacts::class => MockResponse::make(['data' => [], 'meta' => ['total' => 0]])]);

        $this->assertSame([], $this->kajabi($mockClient)->bundles->enrollmentsForUser(5001, 'nobody@example.com'));
        $mockClient->assertSentCount(1);
    }

    // --- Acceptance 3, 4: courses and product type ---

    public function test_product_courses_are_the_offers_course_products(): void
    {
        $mockClient = new MockClient(self::catalogMocks());

        $courses = $this->kajabi($mockClient)->products->courses(5001);

        $this->assertContainsOnlyInstancesOf(Course::class, $courses);
        $this->assertSame([2001, 2002], array_map(fn(Course $c) => $c->id, $courses));
        $this->assertSame(['Course A', 'Course B'], array_map(fn(Course $c) => $c->name, $courses));
        $this->assertSame([2001, 2002], array_map(fn(Course $c) => $c->product_id, $courses));
    }

    public function test_offer_product_type_is_bundle(): void
    {
        $offer = Offer::fromKajabiOffer(Json::data('offers/offer-with-mixed-products.json'));

        $this->assertSame('Bundle', $offer->productable_type);
        $this->assertSame(5001, $offer->productable_id);
    }

    public function test_bundle_get_and_courses(): void
    {
        $mockClient = new MockClient([GetCourseProducts::class => MockResponse::make(Json::catalog())] + self::catalogMocks());
        $bundles = $this->kajabi($mockClient)->bundles;

        $this->assertInstanceOf(BundleServiceInterface::class, $bundles);

        $bundle = $bundles->get(5001);
        $this->assertInstanceOf(Bundle::class, $bundle);
        $this->assertSame(5001, $bundle->id);
        $this->assertSame('All Access', $bundle->name);
        $this->assertSame([2001, 2002], $bundle->course_ids);

        $courses = iterator_to_array($bundles->courses(5001)->items(), false);
        $this->assertSame([2001, 2002], array_map(fn(Course $c) => $c->id, $courses));
    }

    public function test_bundle_name_falls_back_to_internal_title(): void
    {
        $offer = Json::data('offers/offer-with-mixed-products.json');
        unset($offer['attributes']['title']);

        $this->assertSame('All Access (internal)', Bundle::fromKajabiOffer($offer, [])->name);
    }

    public function test_bundle_enrollments_expand_each_contact_by_course_product(): void
    {
        $other = KajabiApiResponses::contact();
        $other['id'] = '124';

        $mockClient = new MockClient([
            GetContactsWithOffer::class => MockResponse::make(['data' => [KajabiApiResponses::contact(), $other], 'meta' => ['total' => 2], 'links' => []]),
        ] + self::catalogMocks());

        $enrollments = iterator_to_array($this->kajabi($mockClient)->bundles->enrollments(5001)->items(), false);

        $this->assertSame(['2001:123', '2002:123', '2001:124', '2002:124'], array_map(fn(Enrollment $e) => $e->id, $enrollments));
        $this->assertSame('5001', $mockClient->getLastPendingRequest()->query()->get('filter[has_offer_id]'));
    }

    // --- Acceptance 5, 6: writes ---

    public function test_bundle_grant_and_revoke_match_the_enrollment_service(): void
    {
        $viaEnrollments = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GrantOffer::class => MockResponse::make([], 204),
            RevokeOffer::class => MockResponse::make([], 204),
        ] + self::catalogMocks());
        $enrollments = $this->kajabi($viaEnrollments)->enrollments;
        $enrollments->create(new CreateEnrollment(user_id: 123, course_id: 5001));
        $enrollments->expire(new DeleteEnrollment(enrollment_id: 0, user_id: 123, course_id: 5001));

        $viaBundles = new MockClient([GrantOffer::class => MockResponse::make([], 204), RevokeOffer::class => MockResponse::make([], 204)]);
        $bundles = $this->kajabi($viaBundles)->bundles;
        $bundles->createEnrollment(self::bundleEnrollment(5001, 123));
        $bundles->expireEnrollment(5001, 123);

        $writes = fn(MockClient $client) => array_values(array_map(
            fn($response) => [
                $response->getPendingRequest()->getMethod()->value,
                $response->getPendingRequest()->getUrl(),
                $response->getPendingRequest()->body()->all(),
            ],
            array_filter(
                $client->getRecordedResponses(),
                fn($response) => $response->getRequest() instanceof GrantOffer || $response->getRequest() instanceof RevokeOffer
            )
        ));

        $this->assertCount(2, $writes($viaBundles));
        $this->assertSame($writes($viaEnrollments), $writes($viaBundles));
        $this->assertSame(['POST', 'https://api.kajabi.com/v1/contacts/123/relationships/offers'], array_slice($writes($viaBundles)[0], 0, 2));
        $this->assertSame('DELETE', $writes($viaBundles)[1][0]);
    }

    public function test_update_makes_no_write_when_the_offer_is_held(): void
    {
        $mockClient = new MockClient([GetContactOfferIds::class => MockResponse::make(self::contactWithMixedOffer())]);

        $response = $this->kajabi($mockClient)->bundles->updateEnrollment(self::bundleEnrollment(5001, 123));

        $this->assertTrue($response->successful());
        $mockClient->assertSentCount(1);
        $mockClient->assertNotSent(GrantOffer::class);
    }

    public function test_update_grants_an_offer_that_is_not_held(): void
    {
        $mockClient = new MockClient([
            GetContactOfferIds::class => MockResponse::make(self::contactWithMixedOffer()),
            GrantOffer::class => MockResponse::make([], 204),
        ]);

        $response = $this->kajabi($mockClient)->bundles->updateEnrollment(self::bundleEnrollment(5002, 123));

        $this->assertSame(204, $response->status());
        $mockClient->assertSent(function ($request, $response) {
            return $request instanceof GrantOffer
                && $response->getPendingRequest()->body()->all()['data'][0]['id'] === '5002';
        });
    }

    public function test_create_many_returns_every_course_product_and_create_the_first(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GrantOffer::class => MockResponse::make([], 204),
        ] + self::catalogMocks());
        $enrollments = $this->kajabi($mockClient)->enrollments;

        $many = $enrollments->createMany(new CreateEnrollment(user_id: 123, course_id: 5001));
        $one = $enrollments->create(new CreateEnrollment(user_id: 123, course_id: 5001));

        $this->assertSame(['2001:123', '2002:123'], array_map(fn(Enrollment $e) => $e->id, $many));
        $this->assertNotNull($many[0]->started_at, 'the grant time is known here');
        $this->assertSame('2001:123', $one->id);
    }

    public function test_create_of_an_offer_without_courses_says_so_after_granting(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GrantOffer::class => MockResponse::make([], 204),
            GetOffer::class => MockResponse::make(['data' => [
                'id' => '6000',
                'type' => 'offers',
                'attributes' => ['title' => 'Community'],
                'relationships' => ['products' => ['data' => [['id' => '2003', 'type' => 'products']]]],
            ]]),
            GetProducts::class => MockResponse::make(Json::catalog()),
        ]);

        try {
            $this->kajabi($mockClient)->enrollments->create(new CreateEnrollment(user_id: 123, course_id: 6000));
            $this->fail('expected UnexpectedValueException');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('was granted', $e->getMessage());
        }

        $mockClient->assertSent(GrantOffer::class);
    }

    public function test_get_reads_a_product_contact_id(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make(Json::load('contacts/contact-with-two-offers.json')),
        ] + self::catalogMocks());
        $enrollments = $this->kajabi($mockClient)->enrollments;

        $this->assertSame('2002:123', $enrollments->get(new ReadEnrollment('2002:123', null, null))->id);
        $this->assertSame('2001:123', $enrollments->get(new ReadEnrollment(0, 123, 2001))->id);

        $this->expectException(\InvalidArgumentException::class);
        $enrollments->get(new ReadEnrollment('2003:123', null, null));
    }

    // --- Acceptance 7: cache ---

    public function test_cache_makes_one_offer_and_one_catalog_request_for_ten_contacts(): void
    {
        $mockClient = new MockClient([
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
            GetContactOffers::class => MockResponse::make(self::contactWithMixedOffer()),
        ] + self::catalogMocks());
        $kajabi = $this->kajabi($mockClient);
        $kajabi->setCache(new ArrayCache());

        foreach (range(1, 10) as $contactId) {
            $this->assertCount(2, $kajabi->bundles->enrollmentsForUser(5001, $contactId));
        }

        $this->assertSame(1, self::sent($mockClient, GetOffer::class));
        $this->assertSame(1, self::sent($mockClient, GetProducts::class));
    }

    public function test_an_injected_cache_is_shared_across_services_and_keyed_by_site(): void
    {
        $cache = new ArrayCache();

        $first = new MockClient(self::catalogMocks());
        $kajabi = $this->kajabi($first);
        $kajabi->setCache($cache, 600);
        $kajabi->products->courseProductIdsForOffer(5001);

        $second = new MockClient(self::catalogMocks());
        $sameSite = $this->kajabi($second);
        $sameSite->setCache($cache);
        $this->assertSame([2001, 2002], $sameSite->products->courseProductIdsForOffer(5001));
        $second->assertNothingSent();

        $this->assertTrue($cache->has('kajabi:0:111:offer_products:5001'));
        $this->assertTrue($cache->has('kajabi:0:111:products'));
        $this->assertFalse($cache->has('kajabi:0:222:products'));
    }

    public function test_course_products_include_cohort_courses(): void
    {
        $mockClient = new MockClient(self::catalogMocks());

        $this->kajabi($mockClient)->products->courseProductIdsForOffer(5001);

        $query = $mockClient->getRecordedResponses()[0]->getPendingRequest()->query()->all();
        $this->assertArrayNotHasKey('filter[exclude_product_types][]', $query);
    }

    public function test_array_cache_expires_entries(): void
    {
        $cache = new ArrayCache();
        $cache->set('a', [1], 60);
        $cache->set('b', [2], 0);
        $cache->set('c', null);

        $this->assertSame([1], $cache->get('a'));
        $this->assertFalse($cache->has('b'));
        $this->assertTrue($cache->has('c'), 'a cached null is still cached');
        $this->assertSame('x', $cache->get('b', 'x'));
    }

    // --- Acceptance 8 ---

    public function test_enrollment_ids_stay_strings(): void
    {
        $this->assertSame('123:456', Enrollment::getEnrollmentId(123, 456));

        $source = implode('', array_map('file_get_contents', array_merge(
            glob(__DIR__ . '/../../../src/*/*.php'),
            glob(__DIR__ . '/../../../src/*/*/*.php'),
            glob(__DIR__ . '/../../../src/*/*/*/*.php'),
        )));
        $this->assertDoesNotMatchRegularExpression('/\(int\)\s*\$\w*enrollment\w*->id/i', $source);
    }

    // --- CourseService lists course products ---

    public function test_course_service_lists_and_reads_course_products(): void
    {
        $mockClient = new MockClient([
            GetCourseProducts::class => MockResponse::make(Json::catalog()),
            GetProduct::class => MockResponse::make(Json::load('products/course-b.json')),
        ]);
        $courses = $this->kajabi($mockClient)->courses;

        $listed = iterator_to_array($courses->courses()->items(), false);
        $this->assertSame([2001, 2002], array_map(fn(Course $c) => $c->id, $listed));

        $course = $courses->get(2002);
        $this->assertSame('Course B', $course->name);
        $this->assertSame(2002, $course->product_id);
    }
}
