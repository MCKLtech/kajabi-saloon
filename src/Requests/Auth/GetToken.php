<?php

namespace WooNinja\KajabiSaloon\Requests\Auth;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Contracts\Body\HasBody;
use Saloon\Traits\Body\HasFormBody;

class GetToken extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(
        private string $clientId,
        private string $clientSecret
    ) {}

    /**
     * Prevent infinite loop by disabling authentication for this request
     */
    public function hasRequestModifier(): bool
    {
        return false;
    }

    public function resolveEndpoint(): string
    {
        return '/oauth/token';
    }

    protected function defaultBody(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials'
        ];
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
    }
}
