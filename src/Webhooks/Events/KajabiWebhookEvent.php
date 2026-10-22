<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

use Carbon\Carbon;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;
use WooNinja\KajabiSaloon\Webhooks\WebhookDelivery;

/**
 * Base for parsed webhook events
 *
 * Deduplication: delivery is at least once. Dedupe retries on deliveryId(). Rarely
 * the same activity arrives twice with different delivery ids (and overlapping
 * hooks always do that), so dedupe on contentKey() too if double-processing hurts.
 * Legitimate repeats (a re-marked lesson, repeat course_started/completed) carry
 * new timestamps and therefore new content keys.
 */
abstract class KajabiWebhookEvent
{
    public function __construct(public readonly WebhookDelivery $delivery)
    {
    }

    public function deliveryId(): string
    {
        return $this->delivery->id;
    }

    public function eventName(): string
    {
        return $this->delivery->event;
    }

    public function eventType(): ?WebhookEvent
    {
        return $this->delivery->eventType();
    }

    /**
     * When the activity happened (not when it was delivered), if known
     */
    abstract public function occurredAt(): ?Carbon;

    /**
     * Content-based dedupe key: identical for two copies of the same activity
     */
    abstract public function contentKey(): string;

    /**
     * Read a timestamp from a resource's meta (where Kajabi puts event times)
     */
    protected static function metaTime(array $resource, string $key): ?Carbon
    {
        $value = $resource['meta'][$key] ?? null;

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * Raw meta value, used in content keys so they match Kajabi's string exactly
     */
    protected static function metaValue(array $resource, string $key): string
    {
        return (string) ($resource['meta'][$key] ?? '');
    }

    protected static function key(string ...$parts): string
    {
        return implode(':', $parts);
    }
}
