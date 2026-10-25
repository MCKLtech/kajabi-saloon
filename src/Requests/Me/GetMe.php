<?php

namespace WooNinja\KajabiSaloon\Requests\Me;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /v1/me - the user the API key belongs to (initials, name, email, role_level)
 *
 * Read-only and cheap; KajabiService::isConnected() uses it as a health check.
 */
class GetMe extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/me';
    }
}
