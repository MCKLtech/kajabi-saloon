<?php

namespace WooNinja\KajabiSaloon\Auth;

use Saloon\Contracts\Authenticator;
use Saloon\Http\PendingRequest;
use Saloon\Contracts\Connector;
use Saloon\Http\Auth\TokenAuthenticator;
use WooNinja\KajabiSaloon\Requests\Auth\GetToken;

class KajabiAuthenticator implements Authenticator
{
    /**
     * Refresh this many seconds before the token's expires_in runs out
     */
    public const EXPIRY_MARGIN_SECONDS = 60;

    private ?AccessToken $token = null;
    public $connector = null;
    private bool $isAuthenticating = false; // Prevent infinite loop

    public function __construct(
        private string $clientId,
        private string $clientSecret
    ) {}

    public function set(PendingRequest $pendingRequest): void
    {
        // Skip authentication if we're currently authenticating (prevents infinite loop)
        if ($this->isAuthenticating) {
            return;
        }

        if (!$this->token || $this->isTokenExpired()) {
            $this->refresh();
        }

        $pendingRequest->headers()->add('Authorization', 'Bearer ' . $this->token->token);
    }

    /**
     * Exchange the client credentials for a new token, whatever the state of the current one
     */
    public function refresh(): AccessToken
    {
        if (!$this->connector) {
            throw new \Exception('Connector is required for authentication');
        }

        // Set flag to prevent infinite loop
        $this->isAuthenticating = true;

        try {

            $response = $this->connector->send(new GetToken(
                $this->clientId,
                $this->clientSecret
            ));

            if ($response->successful()) {
                $this->token = AccessToken::fromResponse($response->json());
            } else {
                throw new \Exception('Failed to authenticate: ' . $response->body());
            }
        } finally {
            // Always reset the flag
            $this->isAuthenticating = false;
        }

        return $this->token;
    }

    public function getAccessToken(): ?string
    {
        return $this->token?->token;
    }

    /**
     * The current token with its expiry and refresh token, or null before the first exchange
     */
    public function getToken(): ?AccessToken
    {
        return $this->token;
    }

    /**
     * Unix time Kajabi's expiry runs out (no safety margin), or null when unknown
     */
    public function getTokenExpiresAt(): ?int
    {
        return $this->token?->expiresAt;
    }

    /**
     * Restore a cached token
     *
     * @param string|AccessToken $token An AccessToken keeps its own expiry; $expiresIn is then ignored
     * @param int|null $expiresIn Seconds until the token expires (null = treat as non-expiring)
     */
    public function setAccessToken(string|AccessToken $token, ?int $expiresIn = null): void
    {
        $this->token = $token instanceof AccessToken
            ? $token
            : new AccessToken($token, $expiresIn, $expiresIn !== null ? time() + $expiresIn : null);
    }

    /**
     * Expired, or within EXPIRY_MARGIN_SECONDS of it
     */
    public function isTokenExpired(): bool
    {
        return $this->token !== null && $this->token->isExpired(self::EXPIRY_MARGIN_SECONDS);
    }
}
