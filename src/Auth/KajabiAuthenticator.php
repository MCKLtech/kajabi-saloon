<?php

namespace WooNinja\KajabiSaloon\Auth;

use Saloon\Contracts\Authenticator;
use Saloon\Http\PendingRequest;
use Saloon\Contracts\Connector;
use Saloon\Http\Auth\TokenAuthenticator;
use WooNinja\KajabiSaloon\Requests\Auth\GetToken;

class KajabiAuthenticator implements Authenticator
{
    private ?string $accessToken = null;
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

        if (!$this->accessToken) {
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

    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
    }
}
