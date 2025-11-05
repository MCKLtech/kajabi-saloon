<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;

class CreateContact extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private array $contactData,
        private ?string $siteId = null
    ) {
    }

    public function resolveEndpoint(): string
    {
        return '/contacts';
    }

    public function defaultBody(): array
    {
        $body = [
            'data' => [
                'type' => 'contacts',
                'attributes' => $this->contactData,
            ]
        ];

        // Add site relationship if site_id is provided
        if ($this->siteId !== null) {
            $body['data']['relationships'] = [
                'site' => [
                    'data' => [
                        'type' => 'sites',
                        'id' => $this->siteId
                    ]
                ]
            ];
        }

        return $body;
    }

    public function createDtoFromResponse($response): User
    {
        return User::fromKajabiContact($response->json('data', []));
    }
}
