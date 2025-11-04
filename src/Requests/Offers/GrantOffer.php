<?php

namespace WooNinja\KajabiSaloon\Requests\Offers;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;

class GrantOffer extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private string $contactId,
        private string $offerId,
        private ?string $siteId = null,
        private bool $sendWelcomeEmail = false
    ) {
    }

    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contactId}/relationships/offers";
    }

    public function defaultBody(): array
    {
        $body = [
            'data' => [
                [
                    'type' => 'offers',
                    'id' => $this->offerId
                ]
            ],
            'meta' => [
                'send_customer_welcome_email' => $this->sendWelcomeEmail
            ]
        ];

        return $body;
    }

    public function createDtoFromResponse($response): bool
    {
        // Grant offer endpoints typically return 204 No Content on success
        return true;
    }
}
