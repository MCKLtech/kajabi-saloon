<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Webhooks;

final class Webhook
{
    public function __construct(
        public int     $id,
        public string  $url,
        public string  $event,
        public bool    $active,
        public ?string $secret,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    )
    {
    }

    public static function fromKajabiWebhook(array $webhook): self
    {
        return new self(
            id: (int) $webhook['id'],
            url: $webhook['attributes']['url'] ?? '',
            event: $webhook['attributes']['event'] ?? '',
            active: (bool) ($webhook['attributes']['active'] ?? true),
            secret: $webhook['attributes']['secret'] ?? null,
            created_at: $webhook['attributes']['created_at'] ?? null,
            updated_at: $webhook['attributes']['updated_at'] ?? null,
        );
    }
}
