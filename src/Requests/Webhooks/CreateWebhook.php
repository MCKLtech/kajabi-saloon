<?php

namespace WooNinja\KajabiSaloon\Requests\Webhooks;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use WooNinja\KajabiSaloon\DataTransferObjects\Webhooks\Webhook;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;

/**
 * POST /v1/hooks - one hook sends one event type to one https:// URL
 */
class CreateWebhook extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private WebhookEvent $event,
        private string $targetUrl,
        private string $siteId,
        private int|string|null $resourceId = null,
    ) {
    }

    public function resolveEndpoint(): string
    {
        return '/hooks';
    }

    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/vnd.api+json'];
    }

    public function defaultBody(): array
    {
        $attributes = [
            'target_url' => $this->targetUrl,
            'event' => $this->event->value,
        ];

        if ($this->resourceId !== null) {
            $attributes['resource_id'] = (string) $this->resourceId;
        }

        return [
            'data' => [
                'type' => 'hooks',
                'attributes' => $attributes,
                'relationships' => [
                    'site' => [
                        'data' => ['type' => 'sites', 'id' => $this->siteId],
                    ],
                ],
            ],
        ];
    }

    public function createDtoFromResponse($response): Webhook
    {
        return Webhook::fromKajabiWebhook($response->json('data'));
    }
}
