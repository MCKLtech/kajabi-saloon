<?php

namespace WooNinja\KajabiSaloon\Services;

/**
 * OAuth Service
 *
 * Kajabi uses OAuth2 Client Credentials flow.
 * Authentication is handled automatically by KajabiAuthenticator.
 *
 * This service is provided for compatibility with Thinkific but is not typically used directly.
 */
class OAuthService extends Resource
{
    /**
     * Refresh OAuth token
     *
     * Note: Kajabi uses Client Credentials flow, not refresh tokens
     *
     * @param mixed $refresh
     * @return mixed
     * @throws \Exception
     */
    public function refresh($refresh)
    {
        throw new \Exception('Kajabi uses OAuth2 Client Credentials flow, not refresh tokens. Authentication is handled automatically.');
    }

    /**
     * Get OAuth token
     *
     * Note: This is handled automatically by KajabiAuthenticator
     *
     * @return mixed
     * @throws \Exception
     */
    public function token()
    {
        throw new \Exception('Token retrieval is handled automatically by KajabiAuthenticator. You do not need to call this method directly.');
    }
}
