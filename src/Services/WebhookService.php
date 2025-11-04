<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Webhooks\Webhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhooks;

class WebhookService extends Resource
{
    public function get(int $webhook_id): Webhook
    {
        return $this->connector->send(new GetWebhook($webhook_id))->dtoOrFail();
    }

    public function webhooks(array $filters = []): Paginator
    {
        return $this->connector->paginate(new GetWebhooks($filters));
    }

    // Note: Kajabi uses /hooks endpoint, not /webhooks like Thinkific
}
