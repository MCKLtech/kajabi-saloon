<?php

namespace Tests\Integration;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\RequestException;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\DeleteEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\ReadEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\UpdateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\CreateUser;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOfferIds;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GrantOffer;
use WooNinja\KajabiSaloon\Requests\Offers\RevokeOffer;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\CreateBundleEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\UpdateBundleEnrollmentInterface;

/**
 * The offer-as-bundle model against the live Kajabi API. Opt-in:
 *
 *   KAJABI_LIVE_TESTS=1 vendor/bin/phpunit tests/Integration
 *
 * Credentials come from .env (KAJABI_CLIENT_ID, KAJABI_CLIENT_SECRET,
 * KAJABI_SITE_ID) or the environment. Read tests use whatever the site has.
 * Write tests also need KAJABI_TEST_CONTACT_EMAIL (optionally
 * KAJABI_TEST_CONTACT_ID): a contact used only for testing. It's created if
 * missing, grants and revokes offers on it, and is left holding the offers it
 * started with. Nothing site-specific is hard-coded here.
 *
 * Expect a few hundred requests (the shared ~800/min budget applies).
 */
class LiveBundleTest extends TestCase
{
    private static ?KajabiService $kajabi = null;
    private static array $env = [];

    public static function setUpBeforeClass(): void
    {
        $file = dirname(__DIR__, 2) . '/.env';
        $values = [];

        if (is_readable($file)) {
            foreach (file($file) as $line) {
                if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                    $values[$m[1]] = trim($m[2], " \t\n\r\"'");
                }
            }
        }

        // .env first: phpunit.xml sets placeholder credentials in the environment
        foreach (['KAJABI_LIVE_TESTS', 'KAJABI_CLIENT_ID', 'KAJABI_CLIENT_SECRET', 'KAJABI_SITE_ID', 'KAJABI_TEST_CONTACT_EMAIL', 'KAJABI_TEST_CONTACT_ID'] as $key) {
            $value = getenv($key);
            self::$env[$key] = $key === 'KAJABI_LIVE_TESTS' && $value !== false ? $value : ($values[$key] ?? ($value === false ? null : $value));
        }
    }

    protected function setUp(): void
    {
        if (self::$env['KAJABI_LIVE_TESTS'] !== '1') {
            $this->markTestSkipped('Live API tests: set KAJABI_LIVE_TESTS=1');
        }

        if (in_array(self::$env['KAJABI_CLIENT_ID'], [null, '', 'test-client-id', 'your-client-id-here'], true)) {
            $this->markTestSkipped('Live API tests need KAJABI_CLIENT_ID/SECRET/SITE_ID in .env');
        }

        self::$kajabi ??= new KajabiService(
            self::$env['KAJABI_CLIENT_ID'],
            self::$env['KAJABI_CLIENT_SECRET'],
            self::$env['KAJABI_SITE_ID']
        );
        self::$kajabi->connector()->setStrictFilters();
    }

    private function kajabi(): KajabiService
    {
        return self::$kajabi;
    }

    /**
     * Raw resources of every offer on the site
     *
     * @return array<int, array>
     */
    private function offers(): array
    {
        $offers = [];

        foreach ($this->kajabi()->offers->offers(['limit' => 100]) as $response) {
            foreach ($response->json('data', []) as $offer) {
                $offers[(int) $offer['id']] = $offer;
            }
        }

        return $offers;
    }

    private static function productIdsOf(array $offer): array
    {
        return array_map(fn(array $p) => (int) $p['id'], $offer['relationships']['products']['data'] ?? []);
    }

    /**
     * An offer with at least two course products, preferring one that also grants non-course products
     */
    private function mixedOffer(): int
    {
        $courseIds = array_keys($this->kajabi()->products->courseProductResources());
        $best = null;
        $bestScore = [-1, -1];

        foreach ($this->offers() as $id => $offer) {
            $products = self::productIdsOf($offer);
            $courses = count(array_intersect($products, $courseIds));
            $score = [$courses >= 2 && count($products) > $courses ? 1 : 0, $courses];

            if ($courses >= 2 && $score > $bestScore) {
                [$best, $bestScore] = [$id, $score];
            }
        }

        if ($best === null) {
            $this->markTestSkipped('No offer on this site grants two course products');
        }

        return $best;
    }

    private function heldOffers(int $contactId): array
    {
        return $this->kajabi()->connector()->send(new GetContactOfferIds($contactId))->dtoOrFail();
    }

    /**
     * Kajabi can lag after a write: wait up to ~10s
     */
    private function eventually(callable $condition, string $message): void
    {
        for ($i = 0; $i < 10; $i++) {
            if ($condition()) {
                $this->addToAssertionCount(1);

                return;
            }
            sleep(1);
        }

        $this->fail($message);
    }

    private static function bundleEnrollment(int $offerId, int $contactId): CreateBundleEnrollmentInterface&UpdateBundleEnrollmentInterface
    {
        return new class($offerId, $contactId, null, null) implements CreateBundleEnrollmentInterface, UpdateBundleEnrollmentInterface {
            public function __construct(
                public int $productable_id,
                public int $user_id,
                public ?Carbon $activated_at,
                public ?Carbon $expiry_date,
            ) {}
        };
    }

    /**
     * Course product enrollment ids the contact should have for these offers
     *
     * @return string[] sorted
     */
    private function expectedEnrollmentIds(int $contactId, array $offerIds): array
    {
        $ids = [];

        foreach ($offerIds as $offerId) {
            foreach ($this->kajabi()->products->courseProductIdsForOffer($offerId) as $productId) {
                $ids[Enrollment::getEnrollmentId($productId, $contactId)] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    private function actualEnrollmentIds(int $contactId): array
    {
        $ids = array_map(
            fn(Enrollment $e) => $e->id,
            iterator_to_array($this->kajabi()->enrollments->enrollmentsForUser($contactId)->items(), false)
        );
        sort($ids);

        return $ids;
    }

    // --- Read-only ---

    public function test_course_products_are_the_products_of_type_course(): void
    {
        $all = [];
        foreach ($this->kajabi()->products->kajabiProducts(['limit' => 100]) as $response) {
            foreach ($response->json('data', []) as $product) {
                $all[(int) $product['id']] = $product['attributes']['product_type_name'] ?? null;
            }
        }

        $expected = array_keys(array_filter($all, fn($type) => $type === 'Course'));

        $this->assertSame($expected, array_keys($this->kajabi()->products->courseProductResources()));
    }

    public function test_an_offer_resolves_to_its_course_products(): void
    {
        $offerId = $this->mixedOffer();
        $offer = $this->kajabi()->connector()->send(new GetOffer($offerId))->json('data');
        $this->assertArrayHasKey('data', $offer['relationships']['products'], 'GET /offers/{id} carries its products');

        $courseIds = array_keys($this->kajabi()->products->courseProductResources());
        $expected = array_values(array_intersect(self::productIdsOf($offer), $courseIds));
        $resolved = $this->kajabi()->products->courseProductIdsForOffer($offerId);
        $this->assertSame($expected, $resolved);

        $bundle = $this->kajabi()->bundles->get($offerId);
        $this->assertSame($resolved, $bundle->course_ids);
        $this->assertNotSame('', $bundle->name);

        $listed = array_map(fn($c) => $c->id, iterator_to_array($this->kajabi()->bundles->courses($offerId)->items(), false));
        sort($listed);
        $sorted = $resolved;
        sort($sorted);
        $this->assertSame($sorted, $listed);

        $courses = $this->kajabi()->products->courses($offerId);
        $this->assertSame($resolved, array_map(fn($c) => $c->id, $courses));
        $this->assertSame($resolved, array_map(fn($c) => $c->product_id, $courses));
        $this->assertSame('Bundle', $this->kajabi()->products->get($offerId)->productable_type);
    }

    public function test_membership_filters_narrow_the_contact_list(): void
    {
        $offerId = $this->mixedOffer();
        $all = $this->kajabi()->users->users(['limit' => 1, 'max_pages' => 1]);
        iterator_to_array($all->items(), false);
        $contacts = $all->getTotalAPIResults();

        $holders = $this->kajabi()->bundles->enrollments($offerId, ['limit' => 5, 'max_pages' => 1]);
        $rows = iterator_to_array($holders->items(), false);
        $this->assertLessThan($contacts, $holders->getTotalAPIResults(), 'filter[has_offer_id] narrows /contacts');

        if ($rows === []) {
            $this->markTestSkipped("No contact holds offer {$offerId}");
        }

        $contactIds = array_values(array_unique(array_map(fn(Enrollment $e) => $e->user_id, $rows)));
        $this->assertCount(count($contactIds) * count($this->kajabi()->products->courseProductIdsForOffer($offerId)), $rows);

        $productId = $rows[0]->course_id;
        $withProduct = $this->kajabi()->enrollments->enrollmentsForCourse($productId, ['limit' => 3, 'max_pages' => 1]);
        $sample = iterator_to_array($withProduct->items(), false);
        $this->assertLessThan($contacts, $withProduct->getTotalAPIResults(), 'filter[has_product_id] narrows /contacts');

        // Each sampled contact really holds an offer granting the product
        foreach ($sample as $enrollment) {
            $grants = false;
            foreach ($this->heldOffers($enrollment->user_id) as $offer) {
                if (in_array($productId, $this->kajabi()->products->courseProductIdsForOffer($offer), true)) {
                    $grants = true;
                    break;
                }
            }
            $this->assertTrue($grants, "contact {$enrollment->user_id} has product {$productId} through an offer");
        }
    }

    public function test_a_holders_enrollments_are_the_union_of_their_offers_course_products(): void
    {
        $offerId = $this->mixedOffer();
        $rows = iterator_to_array($this->kajabi()->bundles->enrollments($offerId, ['limit' => 1, 'max_pages' => 1])->items(), false);

        if ($rows === []) {
            $this->markTestSkipped("No contact holds offer {$offerId}");
        }

        $contactId = $rows[0]->user_id;
        $held = $this->heldOffers($contactId);
        $this->assertContains($offerId, $held);

        $actual = $this->actualEnrollmentIds($contactId);
        $this->assertSame($this->expectedEnrollmentIds($contactId, $held), $actual);
        $this->assertSame($actual, array_values(array_unique($actual)));

        $bundleRows = $this->kajabi()->bundles->enrollmentsForUser($offerId, $contactId);
        $this->assertSame($this->kajabi()->products->courseProductIdsForOffer($offerId), array_map(fn(Enrollment $e) => $e->course_id, $bundleRows));
        $this->assertNotContains(true, array_map(fn(Enrollment $e) => $e->expired, $bundleRows));

        $first = $bundleRows[0];
        $this->assertTrue($this->kajabi()->enrollments->isUserEnrolledInCourse($contactId, $first->course_id));
        $this->assertSame($first->id, $this->kajabi()->enrollments->get(new ReadEnrollment($first->id, null, null))->id);
    }

    // --- Writes on the test contact ---

    public function test_grant_update_and_revoke_on_the_test_contact(): void
    {
        $email = self::$env['KAJABI_TEST_CONTACT_EMAIL'];

        if (!$email) {
            $this->markTestSkipped('Write tests need KAJABI_TEST_CONTACT_EMAIL (a contact used only for testing)');
        }

        $kajabi = $this->kajabi();
        $contactId = (int) (self::$env['KAJABI_TEST_CONTACT_ID'] ?: 0);

        if ($contactId === 0) {
            $contact = $kajabi->users->findByEmail($email)
                ?? $kajabi->users->create(new CreateUser(first_name: 'API', last_name: 'Test', email: $email));
            $contactId = $contact->id;
        }

        $start = $this->heldOffers($contactId);

        // A: two+ courses; B: overlaps A but also differs from it, or failing that just overlaps
        $courseIds = array_keys($kajabi->products->courseProductResources());
        $courses = [];
        foreach ($this->offers() as $id => $offer) {
            if (!in_array($id, $start, true)) {
                $courses[$id] = array_values(array_intersect(self::productIdsOf($offer), $courseIds));
            }
        }
        uasort($courses, fn($a, $b) => count($a) <=> count($b)); // smallest offers first: fewest side effects

        $pair = null;
        foreach ([true, false] as $needBOnly) {
            foreach ($courses as $a => $ca) {
                if (count($ca) < 2) {
                    continue;
                }
                foreach ($courses as $b => $cb) {
                    if ($b !== $a && array_intersect($ca, $cb) && (!$needBOnly || array_diff($cb, $ca))) {
                        $pair = [$a, $b];
                        break 3;
                    }
                }
            }
        }

        if ($pair === null) {
            $this->markTestSkipped('No two offers on this site share a course product');
        }

        [$a, $b] = $pair;
        $coursesA = $kajabi->products->courseProductIdsForOffer($a);
        $coursesB = $kajabi->products->courseProductIdsForOffer($b);
        $shared = array_values(array_intersect($coursesA, $coursesB));
        $onlyB = array_values(array_diff($coursesB, $coursesA));

        try {
            // bundles: update grants a missing offer, then writes nothing once it's held
            $this->assertFalse($kajabi->bundles->isUserEnrolled($a, $contactId));
            $granted = $kajabi->bundles->updateEnrollment(self::bundleEnrollment($a, $contactId));
            $this->assertInstanceOf(GrantOffer::class, $granted->getRequest());
            $this->assertSame(200, $granted->status());
            $this->assertStringContainsString('application/vnd.api+json', $granted->header('Content-Type'));
            $this->eventually(fn() => in_array($a, $this->heldOffers($contactId), true), 'A held after update');

            $this->assertSame($coursesA, array_map(fn(Enrollment $e) => $e->course_id, $kajabi->bundles->enrollmentsForUser($a, $contactId)));
            $this->assertInstanceOf(GetContactOfferIds::class, $kajabi->bundles->updateEnrollment(self::bundleEnrollment($a, $contactId))->getRequest());

            // bundles: expire, then expiring again is a no-op (Kajabi's raw answer is a 400)
            $revoked = $kajabi->bundles->expireEnrollment($a, $contactId);
            $this->assertSame(200, $revoked->status());
            $this->eventually(fn() => !in_array($a, $this->heldOffers($contactId), true), 'A not held after expire');
            $this->assertSame([], $kajabi->bundles->enrollmentsForUser($a, $contactId));

            try {
                $kajabi->connector()->send(new RevokeOffer((string) $contactId, (string) $a));
                $this->fail('Kajabi should reject revoking an offer that is not held');
            } catch (RequestException $e) {
                $this->assertSame(400, $e->getResponse()->status());
                $this->assertSame('Cannot remove relationships', $e->getResponse()->json('errors.0.detail'));
            }

            $again = $kajabi->bundles->expireEnrollment($a, $contactId);
            $this->assertTrue($again->successful());
            $this->assertInstanceOf(GetContactOfferIds::class, $again->getRequest());
            $this->assertTrue($kajabi->enrollments->expire(new DeleteEnrollment(enrollment_id: 0, user_id: $contactId, course_id: $a))->successful());

            // bundles: create
            $this->assertSame(200, $kajabi->bundles->createEnrollment(self::bundleEnrollment($a, $contactId))->status());
            $this->eventually(fn() => $kajabi->bundles->isUserEnrolled($a, $contactId), 'A held after create');

            // enrollments: a second offer sharing a course gives that course once
            $many = $kajabi->enrollments->createMany(new CreateEnrollment(user_id: $contactId, course_id: $b));
            $this->assertSame($coursesB, array_map(fn(Enrollment $e) => $e->course_id, $many));
            $this->assertNotNull($many[0]->started_at);
            $this->eventually(fn() => !array_diff([$a, $b], $this->heldOffers($contactId)), 'A and B held');
            $this->assertSame($this->expectedEnrollmentIds($contactId, array_merge($start, [$a, $b])), $this->actualEnrollmentIds($contactId));

            foreach ($shared as $productId) {
                $id = Enrollment::getEnrollmentId($productId, $contactId);
                $this->assertSame($id, $kajabi->enrollments->get(new ReadEnrollment($id, null, null))->id);
            }

            // enrollments: revoking B keeps the shared course (A still grants it)
            $kajabi->enrollments->expire(new DeleteEnrollment(enrollment_id: 0, user_id: $contactId, course_id: $b));
            $this->eventually(fn() => !in_array($b, $this->heldOffers($contactId), true), 'B not held after expire');
            foreach ($shared as $productId) {
                $this->assertTrue($kajabi->enrollments->isUserEnrolledInCourse($contactId, $productId), "shared course {$productId} kept");
            }
            foreach ($onlyB as $productId) {
                if (!in_array(Enrollment::getEnrollmentId($productId, $contactId), $this->expectedEnrollmentIds($contactId, $start), true)) {
                    $this->assertFalse($kajabi->enrollments->isUserEnrolledInCourse($contactId, $productId), "B-only course {$productId} gone");
                }
            }

            // enrollments: update with a past expiry revokes; create returns the first course
            $kajabi->enrollments->update(new UpdateEnrollment(0, null, Carbon::now()->subDay(), $contactId, $a));
            $this->eventually(fn() => !in_array($a, $this->heldOffers($contactId), true), 'A not held after update with past expiry');
            $one = $kajabi->enrollments->create(new CreateEnrollment(user_id: $contactId, course_id: $a));
            $this->assertSame(Enrollment::getEnrollmentId($coursesA[0], $contactId), $one->id);
            $this->eventually(fn() => in_array($a, $this->heldOffers($contactId), true), 'A held after create()');

            // enrollments: unenroll by id (email search can lag behind a new contact)
            $kajabi->enrollments->unenroll($contactId, $a);
            $this->eventually(fn() => $this->heldOffers($contactId) == $start, 'back to the starting offers');
            $this->assertSame($this->expectedEnrollmentIds($contactId, $start), $this->actualEnrollmentIds($contactId));
        } finally {
            foreach (array_diff($this->heldOffers($contactId), $start) as $offerId) {
                $kajabi->connector()->send(new RevokeOffer((string) $contactId, (string) $offerId));
            }
        }
    }
}
