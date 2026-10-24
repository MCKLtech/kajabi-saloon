<?php

namespace WooNinja\KajabiSaloon\Auth;

/**
 * An OAuth token from POST /v1/oauth/token
 *
 * Kajabi issues client-credentials tokens valid for 7 days (expires_in 604800)
 * along with a refresh_token. Cache the whole object (or its fields) and hand
 * it back with KajabiService::setAccessToken() to skip the exchange.
 */
final class AccessToken
{
    public function __construct(
        public readonly string  $token,
        public readonly ?int    $expiresIn,     // seconds, as sent by Kajabi; null = no expiry given
        public readonly ?int    $expiresAt,     // Unix time Kajabi's expiry runs out (no safety margin)
        public readonly ?string $refreshToken = null,
        public readonly ?string $tokenType = null,
    )
    {
    }

    /**
     * @param array $data The token response body
     * @param int|null $issuedAt Unix time the response arrived (default: now)
     */
    public static function fromResponse(array $data, ?int $issuedAt = null): self
    {
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : null;

        return new self(
            token: (string) $data['access_token'],
            expiresIn: $expiresIn,
            expiresAt: $expiresIn !== null ? ($issuedAt ?? time()) + $expiresIn : null,
            refreshToken: $data['refresh_token'] ?? null,
            tokenType: $data['token_type'] ?? null,
        );
    }

    /**
     * Expired, or due to expire within $marginSeconds. A token without an expiry never expires.
     */
    public function isExpired(int $marginSeconds = 0): bool
    {
        return $this->expiresAt !== null && time() >= $this->expiresAt - $marginSeconds;
    }

    /**
     * Seconds left before expiry (0 once expired), or null when no expiry is known
     */
    public function secondsRemaining(): ?int
    {
        return $this->expiresAt !== null ? max(0, $this->expiresAt - time()) : null;
    }
}
