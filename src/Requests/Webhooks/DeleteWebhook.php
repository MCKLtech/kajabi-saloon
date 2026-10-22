<?php

namespace WooNinja\KajabiSaloon\Requests\Webhooks;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * DELETE /v1/hooks/{id} - returns 204
 */
class DeleteWebhook extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(private int $webhookId) {}

    public function resolveEndpoint(): string { return "/hooks/{$this->webhookId}"; }
}
