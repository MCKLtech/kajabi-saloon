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

    private function connector(MockClient $mockClient, int $limit = 2): KajabiConnector
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

    public function test_defaults_throw_and_stay_well_under_the_ip_limit(): void
    {
        $connector = new KajabiConnector();

        $this->assertFalse($connector->sleepOnRateLimit, 'throwing is the default; sleeping is opt-in');
        $this->assertSame(KajabiConnector::DEFAULT_RATE_LIMIT, $connector->rateLimit);
        $this->assertSame(KajabiConnector::DEFAULT_RATE_LIMIT_NAME, $connector->rateLimitName);
        $this->assertLessThan(KajabiConnector::KAJABI_IP_LIMIT_PER_MINUTE, $connector->rateLimit);
    }

    public function test_limit_throws_by_default(): void
    {
        $connector = $this->connector(new MockClient([self::ok(), self::ok(), self::ok()]));
        $this->sendTimes($connector, 2);

        $this->expectException(RateLimitReachedException::class);
        $connector->send(new GetContact(1));
    }

    public function test_connectors_share_one_bucket_by_default(): void
    {
        $siteA = $this->connector(new MockClient([self::ok(), self::ok()]));
        $siteB = $this->connector(new MockClient([self::ok()]));

        $this->sendTimes($siteA, 2);

        $this->expectException(RateLimitReachedException::class);
        $siteB->send(new GetContact(1));
    }

    public function test_separate_names_get_separate_buckets(): void
    {
        $siteA = $this->connector(new MockClient([self::ok(), self::ok()]));
        $siteB = $this->connector(new MockClient([self::ok()]));
        $siteB->setRateLimitName('kajabi-api:second-egress-ip');

        $this->sendTimes($siteA, 2);
        $siteB->send(new GetContact(1));

        $this->assertSame(0, $siteB->secondsUntilAvailable());
        $this->assertGreaterThan(0, $siteA->secondsUntilAvailable());
    }

    public function test_subclasses_share_the_bucket(): void
    {
        $base = $this->connector(new MockClient([self::ok(), self::ok()]));
        $sub = new class extends KajabiConnector {};
        $sub->withMockClient(new MockClient([self::ok()]))->setRateLimit(2);

        $this->sendTimes($base, 2);

        $this->expectException(RateLimitReachedException::class);
        $sub->send(new GetContact(1));
    }

    public function test_sleep_waits_for_the_window_instead_of_throwing(): void
    {
        $mockClient = new MockClient([self::ok(), self::ok(), self::ok()]);
        $connector = $this->connector($mockClient);
        $connector->setSleepOnRateLimit();

        $this->sendTimes($connector, 3);

        $mockClient->assertSentCount(3);
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThan(0, $this->sleeps[0]);
        $this->assertLessThanOrEqual(60_000_000, $this->sleeps[0], 'at most the one-minute window');
    }

    public function test_sleep_longer_than_the_cap_throws(): void
    {
        $connector = $this->connector(new MockClient([self::ok(), self::ok(), self::ok()]));
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
