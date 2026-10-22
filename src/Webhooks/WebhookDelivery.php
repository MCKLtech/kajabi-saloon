<?php

namespace WooNinja\KajabiSaloon\Webhooks;

use WooNinja\KajabiSaloon\Enums\WebhookEvent;
use WooNinja\KajabiSaloon\Exceptions\InvalidWebhookPayloadException;

/**
 * Raw inbound webhook delivery: {"id": "<delivery uuid>", "event": "...", "payload": [...]}
 *
 * The id identifies the delivery (a retry keeps it) - it is not a member,
 * lesson or course id. Resources are looked up by JSON:API type, never by
 * position.
 */
final class WebhookDelivery
{
    /**
     * @param array[] $payload JSON:API resource objects
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly array  $payload,
    )
    {
    }

    public static function fromJson(string $body): self
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidWebhookPayloadException('Webhook body is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidWebhookPayloadException('Webhook body must be a JSON object');
        }

        return self::fromArray($decoded);
    }

    public static function fromArray(array $body): self
    {
        if (!isset($body['id']) || !is_scalar($body['id']) || (string) $body['id'] === '') {
            throw new InvalidWebhookPayloadException('Webhook body is missing the delivery id');
        }

        if (!isset($body['event']) || !is_string($body['event']) || $body['event'] === '') {
            throw new InvalidWebhookPayloadException('Webhook body is missing the event name');
        }

        if (!isset($body['payload']) || !is_array($body['payload'])) {
            throw new InvalidWebhookPayloadException('Webhook body is missing the payload array');
        }

        return new self((string) $body['id'], $body['event'], array_values($body['payload']));
    }

    /**
     * The event as an enum, or null for an event this SDK doesn't know yet
     */
    public function eventType(): ?WebhookEvent
    {
        return WebhookEvent::tryFrom($this->event);
    }

    /**
     * First resource of the given JSON:API type, or null
     */
    public function resource(string $type): ?array
    {
        foreach ($this->payload as $resource) {
            if (is_array($resource) && ($resource['type'] ?? null) === $type) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * First resource of the given type, or an exception naming what's missing
     */
    public function requireResource(string $type): array
    {
        return $this->resource($type)
            ?? throw new InvalidWebhookPayloadException("{$this->event} payload has no \"{$type}\" resource");
    }

    /**
     * All resources of the given JSON:API type
     *
     * @return array[]
     */
    public function resources(string $type): array
    {
        return array_values(array_filter(
            $this->payload,
            fn($resource) => is_array($resource) && ($resource['type'] ?? null) === $type
        ));
    }
}
