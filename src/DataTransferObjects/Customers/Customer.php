<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Customers;

final class Customer
{
    public function __construct(
        public int     $id,
        public string  $name,
        public string  $email,
        public ?string $avatar,
        public ?string $external_user_id,
        public ?string $public_bio,
        public ?string $public_location,
        public ?string $public_website,
        public ?array  $socials,
        public string  $net_revenue,
        public int     $sign_in_count,
        public ?string $last_request_at,
        public ?string $bounced_at,
        public string  $created_at,
        public string  $updated_at,
    )
    {
    }

    /**
     * Create Customer from Kajabi Customer API response
     */
    public static function fromKajabiCustomer(array $customer): self
    {
        return new self(
            id: (int) $customer['id'],
            name: $customer['attributes']['name'] ?? '',
            email: $customer['attributes']['email'] ?? '',
            avatar: $customer['attributes']['avatar'] ?? null,
            external_user_id: $customer['attributes']['external_user_id'] ?? null,
            public_bio: $customer['attributes']['public_bio'] ?? null,
            public_location: $customer['attributes']['public_location'] ?? null,
            public_website: $customer['attributes']['public_website'] ?? null,
            socials: $customer['attributes']['socials'] ?? null,
            net_revenue: $customer['attributes']['net_revenue'] ?? '0.0',
            sign_in_count: (int) ($customer['attributes']['sign_in_count'] ?? 0),
            last_request_at: $customer['attributes']['last_request_at'] ?? null,
            bounced_at: $customer['attributes']['bounced_at'] ?? null,
            created_at: $customer['attributes']['created_at'] ?? '',
            updated_at: $customer['attributes']['updated_at'] ?? '',
        );
    }
}
