<?php

namespace WooNinja\KajabiSaloon\Requests\Webhooks;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Webhooks\Webhook;

class GetWebhook extends Request
{
    protected Method $method = Method::GET;
    
    public function __construct(private int $webhookId) {}
    
    public function resolveEndpoint(): string { return "/hooks/{$this->webhookId}"; }
    
    public function createDtoFromResponse($response): Webhook
    {
        return Webhook::fromKajabiWebhook($response->json('data'));
    }
}
