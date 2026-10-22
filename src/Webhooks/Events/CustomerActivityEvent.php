<?php

namespace WooNinja\KajabiSaloon\Webhooks\Events;

use Carbon\Carbon;
use WooNinja\KajabiSaloon\DataTransferObjects\Customers\Customer;
use WooNinja\KajabiSaloon\Webhooks\WebhookDelivery;

/**
 * Event whose subject is a member sent as a "customers" resource, with the
 * activity time in meta on that resource.
 */
abstract class CustomerActivityEvent extends KajabiWebhookEvent
{
    public readonly Customer $customer;
    public readonly ?Carbon $occurred_at;
    protected readonly array $customerResource;

    public function __construct(WebhookDelivery $delivery)
    {
        parent::__construct($delivery);

        $this->customerResource = $delivery->requireResource('customers');
        $this->customer = Customer::fromKajabiCustomer($this->customerResource);
        $this->occurred_at = self::metaTime($this->customerResource, static::metaTimeKey());
    }

    /**
     * Key in the customer resource's meta that holds the activity time
     */
    abstract protected static function metaTimeKey(): string;

    public function customerId(): int
    {
        return $this->customer->id;
    }

    /**
     * The member's Contact ID (the SDK's user_id), if the payload links one
     */
    public function contactId(): ?int
    {
        return $this->customer->contact_id;
    }

    public function occurredAt(): ?Carbon
    {
        return $this->occurred_at;
    }

    protected function rawOccurredAt(): string
    {
        return self::metaValue($this->customerResource, static::metaTimeKey());
    }
}
