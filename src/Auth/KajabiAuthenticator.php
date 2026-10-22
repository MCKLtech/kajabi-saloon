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

    private ?string $accessToken = null;
    private ?int $expiresAt = null; // Unix time; null = no known expiry
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

        if (!$this->accessToken || $this->isTokenExpired()) {
            $this->authenticate();
        }

        $pendingRequest->headers()->add('Authorization', 'Bearer ' . $this->accessToken);
    }

    private function authenticate(): void
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
                $data = $response->json();
                $this->accessToken = $data['access_token'];
                $this->expiresAt = isset($data['expires_in'])
                    ? time() + (int)$data['expires_in'] - self::EXPIRY_MARGIN_SECONDS
                    : null;
            } else {
                throw new \Exception('Failed to authenticate: ' . $response->body());
            }
        } finally {
            // Always reset the flag
            $this->isAuthenticating = false;
        }
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * @param int|null $expiresIn Seconds until the token expires (null = treat as non-expiring)
     */
    public function setAccessToken(string $token, ?int $expiresIn = null): void
    {
        $this->accessToken = $token;
        $this->expiresAt = $expiresIn !== null ? time() + $expiresIn - self::EXPIRY_MARGIN_SECONDS : null;
    }

    public function isTokenExpired(): bool
    {
        return $this->expiresAt !== null && time() >= $this->expiresAt;
    }
}
