<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

use Carbon\Carbon;

/**
 * Any event without a dedicated class (purchase, tag_added, ... or one Kajabi
 * adds later). Read resources via $event->delivery->resource('<type>').
 */
final class GenericWebhookEvent extends KajabiWebhookEvent
{
    public function occurredAt(): ?Carbon
    {
        return null;
    }

    public function contentKey(): string
    {
        return self::key($this->eventName(), $this->deliveryId());
    }
}
