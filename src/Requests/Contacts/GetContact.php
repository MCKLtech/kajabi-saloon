<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;

class GetContact extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private int $contactId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contactId}";
    }

    public function createDtoFromResponse($response): User
    {
        $data = $response->json('data');
        
        return User::fromKajabiContact($data);
    }
}
