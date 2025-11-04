<?php

namespace WooNinja\KajabiSaloon\Requests\Offers;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class RevokeOffer extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::DELETE;

    public function __construct(
        private string $contactId,
        private string $offerId,
        private ?string $siteId = null
    ) {
    }

    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contactId}/relationships/offers";
    }

    public function defaultBody(): array
    {
        return [
            'data' => [
                [
                    'type' => 'offers',
                    'id' => $this->offerId
                ]
            ]
        ];
    }

    public function createDtoFromResponse($response): bool
    {
        // Revoke offer endpoints typically return 204 No Content on success
        return true;
    }
}
