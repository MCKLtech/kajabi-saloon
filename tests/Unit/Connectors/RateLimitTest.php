<?php

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;
use Saloon\RateLimitPlugin\Stores\MemoryStore;
use Tests\Fixtures\KajabiApiResponses;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * Kajabi's limit is enforced by Cloudflare per client IP (verified live):
 * a 429 "error code: 1015" with Retry-After: 600 blocks every endpoint - the
 * token exchange too - for any token or site from that IP. So the client-side
 * bucket is shared by default, and a real 429 stops all sending until
 * Retry-After passes.
 */
class RateLimitTest extends TestCase
{
    /** @var int[] microseconds per sleep */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->sleeps = [];
        Config::sleepUsing(function (int $microseconds) {
            $this->sleeps[] = $microseconds;
        });
    }

    protected function tearDown(): void
    {
        Config::sleepUsing(null);
    }

    private static function ok(): MockResponse
    {
        return MockResponse::make(['data' => KajabiApiResponses::contact()]);
    }

    /**
     * Kajabi's real 429 (Cloudflare 1015)
     */
    private static function blocked(): MockResponse
    {
        return MockResponse::make('error code: 1015', 429, ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '600']);
    }

    private function connector(MockClient $mockClient, int $limit = 12): KajabiConnector
    {
        $connector = (new KajabiConnector())->withMockClient($mockClient);
        $connector->setRateLimit($limit);

        return $connector;
    }

    private function sendTimes(KajabiConnector $connector, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $connector->send(new GetContact(1));
        }
    }

    public function test_defaults_throw_and_stay_under_the_ip_limit_in_any_rolling_minute(): void
    {
        $connector = new KajabiConnector();

        $this->assertFalse($connector->sleepOnRateLimit, 'throwing is the default; sleeping is opt-in');
        $this->assertSame(800, $connector->rateLimit, 'whole-IP budget, assuming a shared store');
        $this->assertSame(KajabiConnector::DEFAULT_RATE_LIMIT_NAME, $connector->rateLimitName);
        $this->assertSame(134, $connector->rateLimitSliceSize());

        // A rolling 60 seconds overlaps at most 7 ten-second slices
        $worstRollingMinute = min(2 * $connector->rateLimit, 7 * $connector->rateLimitSliceSize());
        $this->assertLessThan(KajabiConnector::KAJABI_IP_LIMIT_PER_MINUTE, $worstRollingMinute);
    }

    public function test_limits_are_a_minute_budget_and_ten_second_slices(): void
    {
        $connector = new KajabiConnector();
        $connector->setRateLimit(300);

        $limits = [];
        foreach ($connector->getLimits() as $limit) {
            $limits[$limit->getName()] = [$limit->getAllow(), $limit->getReleaseInSeconds()];
        }

        $this->assertSame([300, 60], $limits['kajabi:kajabi-api']);
        $this->assertSame([50, 10], $limits['kajabi:kajabi-api:10s']);
        $this->assertArrayHasKey('kajabi:too_many_attempts_limit', $limits, 'Kajabi 429s are still recorded');
    }

    public function test_slice_throws_once_its_share_is_used(): void
    {
        $connector = $this->connector(new MockClient([self::ok(), self::ok(), self::ok()]), 12);
        $this->sendTimes($connector, 2);

        try {
            $connector->send(new GetContact(1));
            $this->fail('Expected RateLimitReachedException');
        } catch (RateLimitReachedException $e) {
            $this->assertSame('kajabi:kajabi-api:10s', $e->getLimit()->getName());
        }

        $this->assertGreaterThan(0, $connector->secondsUntilAvailable());
        $this->assertLessThanOrEqual(10, $connector->secondsUntilAvailable());
    }

    /**
     * Other workers have used the minute's budget (they share the store), so this
     * process is refused even though its own slice has room
     */
    public function test_minute_budget_used_by_other_workers_throws(): void
    {
        $store = new MemoryStore();
        $store->set('kajabi:kajabi-api', json_encode(['timestamp' => time() + 45, 'hits' => 12]), 45);
        $mockClient = new MockClient([self::ok()]);
        $connector = $this->connector($mockClient, 12);
        $connector->setRateStore($store);

        try {
            $connector->send(new GetContact(1));
            $this->fail('Expected RateLimitReachedException');
        } catch (RateLimitReachedException $e) {
            $this->assertSame('kajabi:kajabi-api', $e->getLimit()->getName());
        }

        $mockClient->assertNothingSent();
        $this->assertGreaterThan(40, $connector->secondsUntilAvailable());
    }

    public function test_connectors_share_one_bucket_by_default(): void
    {
        $siteA = $this->connector(new MockClient([self::ok(), self::ok()]), 12);
        $siteB = $this->connector(new MockClient([self::ok()]), 12);

        $this->sendTimes($siteA, 2);

        $this->expectException(RateLimitReachedException::class);
        $siteB->send(new GetContact(1));
    }

    public function test_separate_names_get_separate_buckets(): void
    {
        $siteA = $this->connector(new MockClient([self::ok(), self::ok()]), 12);
        $siteB = $this->connector(new MockClient([self::ok()]), 12);
        $siteB->setRateLimitName('kajabi-api:second-egress-ip');

        $this->sendTimes($siteA, 2);
        $siteB->send(new GetContact(1));

        $this->assertSame(0, $siteB->secondsUntilAvailable());
        $this->assertGreaterThan(0, $siteA->secondsUntilAvailable());
    }

    public function test_subclasses_share_the_bucket(): void
    {
        $base = $this->connector(new MockClient([self::ok(), self::ok()]), 12);
        $sub = new class extends KajabiConnector {};
        $sub->withMockClient(new MockClient([self::ok()]))->setRateLimit(12);

        $this->sendTimes($base, 2);

        $this->expectException(RateLimitReachedException::class);
        $sub->send(new GetContact(1));
    }

    public function test_sleep_waits_for_the_slice_instead_of_throwing(): void
    {
        $mockClient = new MockClient([self::ok(), self::ok(), self::ok()]);
        $connector = $this->connector($mockClient, 12);
        $connector->setSleepOnRateLimit();

        $this->sendTimes($connector, 3);

        $mockClient->assertSentCount(3);
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThan(0, $this->sleeps[0]);
        $this->assertLessThanOrEqual(10_000_000, $this->sleeps[0], 'at most one 10-second slice');
    }

    public function test_sleep_longer_than_the_cap_throws(): void
    {
        $connector = $this->connector(new MockClient([self::ok(), self::ok(), self::ok()]), 12);
        $connector->setSleepOnRateLimit(true, 0);

        $this->sendTimes($connector, 2);

        try {
            $connector->send(new GetContact(1));
            $this->fail('Expected RateLimitReachedException');
        } catch (RateLimitReachedException) {
            $this->assertSame([], $this->sleeps);
        }
    }

    public function test_kajabi_429_blocks_further_requests_for_retry_after(): void
    {
        $mockClient = new MockClient([self::blocked(), self::ok()]);
        $connector = $this->connector($mockClient, 100);
        $connector->setSleepOnRateLimit();

        try {
            $connector->send(new GetContact(1));
            $this->fail('Expected RateLimitReachedException');
        } catch (RateLimitReachedException $e) {
            $this->assertStringContainsString('too_many_attempts', $e->getLimit()->getName());
        }

        $this->assertGreaterThan(590, $connector->secondsUntilAvailable());

        try {
            $connector->send(new GetContact(1));
            $this->fail('Requests during the block must not be sent');
        } catch (RateLimitReachedException) {
        }

        $mockClient->assertSentCount(1);
        $this->assertSame([], $this->sleeps, 'a 10-minute block is never slept through');
    }

    public function test_seconds_until_available_is_zero_when_free(): void
    {
        $this->assertSame(0, $this->connector(new MockClient([]))->secondsUntilAvailable());
    }

    public function test_service_shares_one_configurable_connector(): void
    {
        $kajabi = new KajabiService('client-id', 'client-secret', 'site-1');

        $this->assertSame($kajabi->connector(), $kajabi->connector());

        $kajabi->connector()->setRateLimit(250);
        $this->assertSame(250, $kajabi->connector()->rateLimit, 'configuring connector() now sticks');

        MemoryStore::clear();
    }

    public function test_requests_have_timeouts(): void
    {
        $mockClient = new MockClient([self::ok(), self::ok()]);
        $connector = $this->connector($mockClient, 100);

        $connector->send(new GetContact(1));
        $config = $mockClient->getLastPendingRequest()->config()->all();
        $this->assertSame(10.0, (float) $config['connect_timeout']);
        $this->assertSame(60.0, (float) $config['timeout']);

        $connector->setTimeouts(5, 120);
        $connector->send(new GetContact(1));
        $config = $mockClient->getLastPendingRequest()->config()->all();
        $this->assertSame(5.0, (float) $config['connect_timeout']);
        $this->assertSame(120.0, (float) $config['timeout']);
    }
}
