<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\Json;
use Tests\Support\RecordingCache;
use WooNinja\KajabiSaloon\Cache\ArrayCache;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * The offer -> course products cache must not hide recent changes: short by
 * default, can be bypassed or turned off, and clears in one call for every
 * worker sharing the store.
 *
 * Offer 5001 grants course A (2001), course B (2002) and access group C (2003).
 */
class CatalogCacheTest extends TestCase
{
    /** @var array<int, array> product resources the mocked catalog returns */
    private array $catalog;

    /** @var int[] product ids the mocked offer grants */
    private array $offerProducts;

    protected function setUp(): void
    {
        $this->catalog = Json::catalog()['data'];
        $this->offerProducts = [2001, 2002, 2003];
    }

    private function mockClient(): MockClient
    {
        return new MockClient([
            GetProducts::class => fn() => MockResponse::make(['data' => $this->catalog, 'links' => []]),
            GetOffer::class => fn() => MockResponse::make(['data' => $this->offer($this->offerProducts)]),
        ]);
    }

    private function offer(array $productIds): array
    {
        $offer = Json::data('offers/offer-with-mixed-products.json');
        $offer['relationships']['products']['data'] = array_map(fn($id) => ['id' => (string) $id, 'type' => 'products'], $productIds);

        return $offer;
    }

    private function kajabi(MockClient $mockClient, ?ArrayCache $cache = null): KajabiService
    {
        $kajabi = new KajabiService('id', 'secret', '111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        if ($cache !== null) {
            $kajabi->setCache($cache);
        }

        return $kajabi;
    }

    private static function sent(MockClient $mockClient, string $requestClass): int
    {
        return count(array_filter(
            $mockClient->getRecordedResponses(),
            fn($response) => $response->getRequest() instanceof $requestClass
        ));
    }

    private function addCourse(int $id): void
    {
        $course = Json::data('products/course-a.json');
        $course['id'] = (string) $id;
        $course['attributes']['title'] = "Course {$id}";
        $this->catalog[] = $course;
        $this->offerProducts[] = $id;
    }

    private static function ids(array $courses): array
    {
        return array_map(fn(Course $c) => $c->id, $courses);
    }

    public function test_default_ttl_is_fifteen_minutes(): void
    {
        $kajabi = new KajabiService('id', 'secret');

        $this->assertSame(900, KajabiService::DEFAULT_CACHE_TTL);
        $this->assertSame(900, $kajabi->cacheTtl());

        $kajabi->setCache(new ArrayCache());
        $this->assertSame(900, $kajabi->cacheTtl(), 'setCache() without a TTL keeps the short default');
    }

    public function test_entries_are_stored_with_the_ttl(): void
    {
        $cache = new RecordingCache();
        $mockClient = $this->mockClient();
        $kajabi = $this->kajabi($mockClient);
        $kajabi->setCache($cache, 120);

        $kajabi->products->courses(5001);

        $this->assertSame(120, $cache->ttls['kajabi:0:111:products']);
        $this->assertSame(120, $cache->ttls['kajabi:0:111:offer_products:5001']);
    }

    public function test_ttl_zero_turns_caching_off(): void
    {
        $cache = new ArrayCache();
        $mockClient = $this->mockClient();
        $kajabi = $this->kajabi($mockClient, $cache);
        $kajabi->setCacheTtl(0);

        $kajabi->products->courses(5001);
        $kajabi->products->courses(5001);

        $this->assertFalse($kajabi->cachingEnabled());
        $this->assertSame(2, self::sent($mockClient, GetOffer::class));
        $this->assertSame(2, self::sent($mockClient, GetProducts::class), 'one catalog read per call, no reload on top');
        $this->assertFalse($cache->has('kajabi:0:111:products'));
    }

    public function test_fresh_bypasses_and_refreshes_the_cache(): void
    {
        $mockClient = $this->mockClient();
        $kajabi = $this->kajabi($mockClient, new ArrayCache());

        $this->assertSame([2001, 2002], self::ids($kajabi->products->courses(5001)));
        $this->addCourse(2004);
        $this->assertSame([2001, 2002], self::ids($kajabi->products->courses(5001)), 'cached');

        $this->assertSame([2001, 2002, 2004], self::ids($kajabi->products->courses(5001, fresh: true)));
        $this->assertSame([2001, 2002, 2004], self::ids($kajabi->products->courses(5001)), 'the fresh result was cached');
        $this->assertSame(2, self::sent($mockClient, GetOffer::class));
        $this->assertSame(2, self::sent($mockClient, GetProducts::class));
    }

    public function test_a_course_newer_than_the_cached_catalog_reloads_it(): void
    {
        $mockClient = $this->mockClient();
        $kajabi = $this->kajabi($mockClient, new ArrayCache());
        $kajabi->products->courses(5001);

        // A new course, added to the offer; the offer read is current (as create() and bundles->get() pass it)
        $this->addCourse(2004);
        $resolved = $kajabi->products->courseProductsForOffer(5001, $this->offer($this->offerProducts));

        $this->assertSame([2001, 2002, 2004], array_keys($resolved));
        $this->assertSame(2, self::sent($mockClient, GetProducts::class));

        $kajabi->products->courses(5001);
        $this->assertSame(2, self::sent($mockClient, GetProducts::class), 'the reloaded catalog is cached');
    }

    public function test_a_product_missing_from_the_catalog_reloads_it_only_once(): void
    {
        // e.g. a deleted product an offer still lists
        $this->offerProducts[] = 9999;
        $mockClient = $this->mockClient();
        $kajabi = $this->kajabi($mockClient, new ArrayCache());

        foreach (range(1, 3) as $call) {
            $this->assertSame([2001, 2002], self::ids($kajabi->products->courses(5001)));
        }

        $this->assertSame(2, self::sent($mockClient, GetProducts::class), 'first read + one reload, then remembered');
    }

    public function test_clear_cache_reaches_every_worker_and_keeps_other_keys(): void
    {
        $store = new ArrayCache();
        $store->set('host:session', 'keep me');

        $first = $this->mockClient();
        $workerA = $this->kajabi($first, $store);
        $workerA->products->courses(5001);

        $second = $this->mockClient();
        $workerB = $this->kajabi($second, $store);
        $workerB->products->courses(5001);
        $second->assertNothingSent();

        $this->addCourse(2004);
        $workerA->clearCache();

        $this->assertSame([2001, 2002, 2004], self::ids($workerB->products->courses(5001)), 'worker B sees the clear');
        $this->assertSame(1, self::sent($second, GetOffer::class));
        $this->assertSame(1, self::sent($second, GetProducts::class));
        $this->assertSame('keep me', $store->get('host:session'));
        $this->assertStringNotContainsString('kajabi:0:', $workerA->cacheKey('products'));
    }

    public function test_clear_cache_covers_every_site(): void
    {
        $kajabi = $this->kajabi($this->mockClient(), new ArrayCache());
        $before = [$kajabi->cacheKey('products')];
        $kajabi->setSiteId('222');
        $before[] = $kajabi->cacheKey('products');

        $kajabi->clearCache();

        $after = [$kajabi->cacheKey('products')];
        $kajabi->setSiteId('111');
        array_unshift($after, $kajabi->cacheKey('products'));

        $this->assertSame([], array_intersect($before, $after));
    }
}

