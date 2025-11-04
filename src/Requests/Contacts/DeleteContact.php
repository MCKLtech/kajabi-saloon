<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteContact extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected int $contactId
    ) {
    }

    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contactId}";
    }
}
