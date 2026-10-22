<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Webhooks;

use WooNinja\KajabiSaloon\Enums\WebhookEvent;

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
        // Kajabi hook fields
        public ?string $target_url = null,
        public ?int    $resource_id = null,
        public ?int    $site_id = null,
    )
    {
    }

    /**
     * Create Webhook from a Kajabi hook resource
     *
     * Kajabi hooks have target_url/resource_id; they have no active flag or
     * signing secret. `url` mirrors target_url for backward compatibility,
     * `active` is always true (a hook exists until deleted) and `secret` is null.
     */
    public static function fromKajabiWebhook(array $webhook): self
    {
        $attributes = $webhook['attributes'] ?? [];
        $targetUrl = $attributes['target_url'] ?? $attributes['url'] ?? '';
        $resourceId = $attributes['resource_id'] ?? null;
        $siteId = $webhook['relationships']['site']['data']['id'] ?? null;

        return new self(
            id: (int) $webhook['id'],
            url: $targetUrl,
            event: $attributes['event'] ?? '',
            active: (bool) ($attributes['active'] ?? true),
            secret: $attributes['secret'] ?? null,
            created_at: $attributes['created_at'] ?? null,
            updated_at: $attributes['updated_at'] ?? null,
            target_url: $targetUrl,
            resource_id: $resourceId !== null ? (int) $resourceId : null,
            site_id: $siteId !== null ? (int) $siteId : null,
        );
    }

    /**
     * The event as an enum, or null for an event this SDK doesn't know yet
     */
    public function eventType(): ?WebhookEvent
    {
        return WebhookEvent::tryFrom($this->event);
    }

    /**
     * Whether this hook only fires for one course (resource_id set)
     */
    public function isCourseScoped(): bool
    {
        return $this->resource_id !== null;
    }
}
