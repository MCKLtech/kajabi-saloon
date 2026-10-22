<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\KajabiApiResponses;
use WooNinja\KajabiSaloon\Auth\KajabiAuthenticator;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Requests\Auth\GetToken;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;

/**
 * Tokens are refreshed before expires_in runs out (minus a safety margin),
 * so long-running syncs don't start failing with 401s.
 */
class TokenExpiryTest extends TestCase
{
    private function setUpConnector(MockClient $mockClient): array
    {
        $authenticator = new KajabiAuthenticator('client-id', 'client-secret');
        $connector = (new KajabiConnector())->authenticate($authenticator)->withMockClient($mockClient);
        $authenticator->connector = $connector;

        return [$connector, $authenticator];
    }

    private function tokenCount(MockClient $mockClient): int
    {
        return count(array_filter(
            $mockClient->getRecordedResponses(),
            fn($response) => $response->getRequest() instanceof GetToken
        ));
    }

    public function test_valid_token_is_reused(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make(['access_token' => 'token-1', 'expires_in' => 3600]),
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
        ]);
        [$connector, $authenticator] = $this->setUpConnector($mockClient);

        $connector->send(new GetContact(123));
        $connector->send(new GetContact(123));

        $this->assertSame(1, $this->tokenCount($mockClient));
        $this->assertFalse($authenticator->isTokenExpired());
    }

    public function test_token_within_margin_of_expiry_is_refreshed(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make([
                'access_token' => 'short-lived',
                'expires_in' => KajabiAuthenticator::EXPIRY_MARGIN_SECONDS - 1,
            ]),
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
        ]);
        [$connector, $authenticator] = $this->setUpConnector($mockClient);

        $connector->send(new GetContact(123));
        $this->assertTrue($authenticator->isTokenExpired());

        $connector->send(new GetContact(123));

        $this->assertSame(2, $this->tokenCount($mockClient));
    }

    public function test_token_without_expires_in_never_expires(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make(['access_token' => 'token-1']),
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
        ]);
        [$connector, $authenticator] = $this->setUpConnector($mockClient);

        $connector->send(new GetContact(123));
        $connector->send(new GetContact(123));

        $this->assertSame(1, $this->tokenCount($mockClient));
        $this->assertFalse($authenticator->isTokenExpired());
    }

    public function test_set_access_token_with_and_without_expiry(): void
    {
        $authenticator = new KajabiAuthenticator('client-id', 'client-secret');

        $authenticator->setAccessToken('cached');
        $this->assertFalse($authenticator->isTokenExpired(), 'no expiry given: trusted as-is (previous behaviour)');

        $authenticator->setAccessToken('cached', 3600);
        $this->assertFalse($authenticator->isTokenExpired());

        $authenticator->setAccessToken('cached', 0);
        $this->assertTrue($authenticator->isTokenExpired());
        $this->assertSame('cached', $authenticator->getAccessToken());
    }

    public function test_expired_cached_token_is_replaced_on_next_request(): void
    {
        $mockClient = new MockClient([
            GetToken::class => MockResponse::make(['access_token' => 'fresh', 'expires_in' => 3600]),
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
        ]);
        [$connector, $authenticator] = $this->setUpConnector($mockClient);
        $authenticator->setAccessToken('stale', 0);

        $connector->send(new GetContact(123));

        $this->assertSame('fresh', $authenticator->getAccessToken());
        $this->assertSame('Bearer fresh', $mockClient->getLastPendingRequest()->headers()->get('Authorization'));
    }
}
