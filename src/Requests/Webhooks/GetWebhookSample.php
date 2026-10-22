<?php

namespace WooNinja\KajabiSaloon\Requests\Webhooks;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;

/**
 * GET /v1/hooks/{event}_sample
 *
 * Returns only the payload array (not the full {id, event, payload} delivery body),
 * with placeholder data in the same shape as a live delivery.
 */
class GetWebhookSample extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private WebhookEvent $event) {}

    public function resolveEndpoint(): string { return $this->event->sampleEndpoint(); }

    public function createDtoFromResponse($response): array
    {
        $json = $response->json();

        // Tolerate a JSON:API {"data": [...]} wrapper as well as a bare array
        if (is_array($json) && array_key_exists('data', $json) && is_array($json['data'])) {
            return $json['data'];
        }

        return is_array($json) ? $json : [];
    }
}
