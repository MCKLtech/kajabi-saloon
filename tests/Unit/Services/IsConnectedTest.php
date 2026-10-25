<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use WooNinja\KajabiSaloon\Auth\KajabiAuthenticator;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Requests\Auth\GetToken;
use WooNinja\KajabiSaloon\Requests\Me\GetMe;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * isConnected() makes a real (read-only) call instead of only building a connector
 */
class IsConnectedTest extends TestCase
{
    private function kajabi(MockClient $mockClient): KajabiService
    {
        $kajabi = new KajabiService('client-id', 'client-secret', 'site-1');
        $authenticator = new KajabiAuthenticator('client-id', 'client-secret');
        $connector = (new KajabiConnector())->authenticate($authenticator)->withMockClient($mockClient);
        $authenticator->connector = $connector;
        $kajabi->setAuthenticator($authenticator);
        $kajabi->setConnector($connector);

        return $kajabi;
    }

    private static function me(): MockResponse
    {
        return MockResponse::make(['data' => ['id' => '1', 'type' => 'users', 'attributes' => ['name' => 'A', 'email' => 'a@example.com']]]);
    }

    public function test_true_when_token_and_read_succeed(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make(['access_token' => 't', 'expires_in' => 604800]),
            GetMe::class => self::me(),
        ]);

        $this->assertTrue($this->kajabi($mockClient)->isConnected());

        $mockClient->assertSent(GetMe::class);
        $this->assertSame('GET', $mockClient->getLastPendingRequest()->getMethod()->value, 'read-only');
        $this->assertSame('/me', $mockClient->getLastPendingRequest()->getRequest()->resolveEndpoint());
    }

    public function test_false_on_bad_credentials(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make(['error' => 'invalid_client'], 401),
        ]);

        $this->assertFalse($this->kajabi($mockClient)->isConnected());
    }

    public function test_false_on_api_error(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make(['access_token' => 't', 'expires_in' => 604800]),
            GetMe::class => MockResponse::make(['errors' => [['status' => '500']]], 500),
        ]);

        $this->assertFalse($this->kajabi($mockClient)->isConnected());
    }

    public function test_false_on_network_failure(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make(['access_token' => 't', 'expires_in' => 604800]),
            GetMe::class => fn(PendingRequest $request) => throw new FatalRequestException(new \RuntimeException('timed out'), $request),
        ]);

        $this->assertFalse($this->kajabi($mockClient)->isConnected());
    }
}
