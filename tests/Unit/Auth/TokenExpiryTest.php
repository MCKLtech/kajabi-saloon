<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\KajabiApiResponses;
use WooNinja\KajabiSaloon\Auth\AccessToken;
use WooNinja\KajabiSaloon\Auth\KajabiAuthenticator;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Requests\Auth\GetToken;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Services\KajabiService;

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

    private function serviceWithMock(MockClient $mockClient): KajabiService
    {
        $kajabi = new KajabiService('client-id', 'client-secret', 'site-1');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));

        return $kajabi;
    }

    /**
     * Kajabi's real token response (verified live): 7-day expiry plus a refresh token
     */
    private function tokenResponse(string $token = 'token-1'): MockResponse
    {
        return MockResponse::make([
            'access_token' => $token,
            'refresh_token' => 'refresh-1',
            'token_type' => 'bearer',
            'expires_in' => 604800,
        ]);
    }

    public function test_access_token_from_response(): void
    {
        $token = AccessToken::fromResponse(
            ['access_token' => 'abc', 'refresh_token' => 'r', 'token_type' => 'bearer', 'expires_in' => 604800],
            1_000_000
        );

        $this->assertSame('abc', $token->token);
        $this->assertSame(604800, $token->expiresIn);
        $this->assertSame(1_604_800, $token->expiresAt);
        $this->assertSame('r', $token->refreshToken);
        $this->assertSame('bearer', $token->tokenType);

        $noExpiry = AccessToken::fromResponse(['access_token' => 'abc']);
        $this->assertNull($noExpiry->expiresAt);
        $this->assertNull($noExpiry->secondsRemaining());
        $this->assertFalse($noExpiry->isExpired(PHP_INT_MAX >> 1));
    }

    public function test_fetch_access_token_returns_expiry_and_refresh_token(): void
    {
        $mockClient = new MockClient([GetToken::class => $this->tokenResponse()]);
        $kajabi = $this->serviceWithMock($mockClient);

        $before = time();
        $token = $kajabi->fetchAccessToken();

        $this->assertSame('token-1', $token->token);
        $this->assertSame(604800, $token->expiresIn);
        $this->assertSame('refresh-1', $token->refreshToken);
        $this->assertGreaterThanOrEqual($before + 604800, $token->expiresAt);
        $this->assertSame($token->expiresAt, $kajabi->getTokenExpiresAt(), 'no safety margin in the exposed expiry');
        $this->assertSame($token, $kajabi->getToken());
        $this->assertSame('token-1', $kajabi->getAccessToken());
    }

    public function test_authenticate_still_returns_the_token_string(): void
    {
        $mockClient = new MockClient([GetToken::class => $this->tokenResponse('token-2')]);

        $this->assertSame('token-2', $this->serviceWithMock($mockClient)->authenticate());
    }

    public function test_fetch_access_token_always_exchanges(): void
    {
        $mockClient = new MockClient([GetToken::class => $this->tokenResponse()]);
        $kajabi = $this->serviceWithMock($mockClient);
        $kajabi->setAccessToken('cached', 3600);

        $kajabi->fetchAccessToken();

        $this->assertSame(1, $this->tokenCount($mockClient));
        $this->assertSame('token-1', $kajabi->getAccessToken());
    }

    public function test_cached_access_token_object_is_restored_with_its_expiry(): void
    {
        $mockClient = new MockClient([
            GetToken::class => $this->tokenResponse('fresh'),
            GetContact::class => MockResponse::make(['data' => KajabiApiResponses::contact()]),
        ]);
        [$connector, $authenticator] = $this->setUpConnector($mockClient);

        $authenticator->setAccessToken(new AccessToken('cached', 604800, time() + 3600));
        $connector->send(new GetContact(123));
        $this->assertSame(0, $this->tokenCount($mockClient));
        $this->assertSame('Bearer cached', $mockClient->getLastPendingRequest()->headers()->get('Authorization'));

        $authenticator->setAccessToken(new AccessToken('old', 604800, time() + KajabiAuthenticator::EXPIRY_MARGIN_SECONDS - 1));
        $this->assertTrue($authenticator->isTokenExpired(), 'inside the safety margin');
        $connector->send(new GetContact(123));
        $this->assertSame('fresh', $authenticator->getAccessToken());
    }
}
