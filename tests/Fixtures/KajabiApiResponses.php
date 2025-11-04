<?php

namespace Tests\Fixtures;

class KajabiApiResponses
{
    public static function contact(): array
    {
        return [
            'id' => '123',
            'type' => 'contacts',
            'attributes' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'phone_number' => '+1-555-0123',
                'business_number' => null,
                'subscribed' => false,
                'address_line_1' => '123 Main St',
                'address_line_2' => 'Apt 4B',
                'address_city' => 'New York',
                'address_state' => 'NY',
                'address_country' => 'USA',
                'address_zip' => '10001',
                'external_user_id' => 'ext_123',
                'custom_1' => 'Custom Value 1',
                'custom_2' => 'Custom Value 2',
                'custom_3' => null,
                'created_at' => '2024-01-15T10:30:00Z',
                'updated_at' => '2024-01-16T14:20:00Z',
            ],
            'relationships' => [
                'site' => [
                    'data' => [
                        'id' => '1',
                        'type' => 'sites',
                    ],
                ],
                'customer' => [
                    'data' => [
                        'id' => '456',
                        'type' => 'customers',
                    ],
                ],
            ],
        ];
    }

    public static function purchase(): array
    {
        return [
            'id' => '789',
            'type' => 'purchases',
            'attributes' => [
                'customer_email' => 'john.doe@example.com',
                'customer_name' => 'John Doe',
                'product_name' => 'Introduction to Marketing',
                'offer_name' => 'Marketing Course Offer',
                'status' => 'active',
                'amount' => 9900,
                'activated_at' => '2024-01-15T10:30:00Z',
                'started_at' => '2024-01-15T10:35:00Z',
                'completed_at' => null,
                'expires_at' => '2025-01-15T10:30:00Z',
                'created_at' => '2024-01-15T10:30:00Z',
                'updated_at' => '2024-01-16T14:20:00Z',
            ],
            'relationships' => [
                'customer' => [
                    'data' => [
                        'id' => '456',
                        'type' => 'customers',
                    ],
                ],
                'offer' => [
                    'data' => [
                        'id' => '101',
                        'type' => 'offers',
                    ],
                ],
                'product' => [
                    'data' => [
                        'id' => '202',
                        'type' => 'products',
                    ],
                ],
            ],
        ];
    }

    public static function course(): array
    {
        return [
            'id' => '202',
            'type' => 'courses',
            'attributes' => [
                'name' => 'Introduction to Marketing',
                'description' => 'Learn the basics of marketing',
                'slug' => 'intro-to-marketing',
                'status' => 'published',
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2024-01-10T12:00:00Z',
            ],
            'relationships' => [
                'product' => [
                    'data' => [
                        'id' => '303',
                        'type' => 'products',
                    ],
                ],
            ],
        ];
    }

    public static function product(): array
    {
        return [
            'id' => '303',
            'type' => 'products',
            'attributes' => [
                'name' => 'Marketing Bundle',
                'description' => 'Complete marketing course bundle',
                'status' => 'published',
                'url' => 'https://example.kajabi.com/products/marketing-bundle',
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2024-01-10T12:00:00Z',
            ],
            'relationships' => [
                'offers' => [
                    'data' => [
                        ['id' => '101', 'type' => 'offers'],
                        ['id' => '102', 'type' => 'offers'],
                    ],
                ],
            ],
        ];
    }

    public static function order(): array
    {
        return [
            'id' => '404',
            'type' => 'orders',
            'attributes' => [
                'total' => '99.00',
                'subtotal' => '99.00',
                'tax' => '0.00',
                'status' => 'completed',
                'created_at' => '2024-01-15T10:30:00Z',
                'updated_at' => '2024-01-15T10:35:00Z',
            ],
            'relationships' => [
                'customer' => [
                    'data' => [
                        'id' => '456',
                        'type' => 'customers',
                    ],
                ],
                'purchases' => [
                    'data' => [
                        ['id' => '789', 'type' => 'purchases'],
                    ],
                ],
            ],
        ];
    }

    public static function offer(): array
    {
        return [
            'id' => '101',
            'type' => 'offers',
            'attributes' => [
                'name' => 'Marketing Course Offer',
                'description' => 'Special offer for marketing course',
                'internal_title' => 'Marketing Offer',
                'price_in_cents' => 9900,
                'payment_type' => 'one_time',
                'token' => 'offer_abc123',
                'payment_method' => 'stripe',
                'price_description' => '$99.00',
                'checkout_url' => 'https://example.kajabi.com/checkout/offer_abc123',
                'recurring_offer' => false,
                'subscription' => false,
                'one_time' => true,
                'single' => false,
                'free' => false,
                'currency' => 'USD',
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2024-01-05T08:00:00Z',
            ],
            'relationships' => [
                'products' => [
                    'data' => [
                        ['id' => '303', 'type' => 'products'],
                    ],
                ],
            ],
        ];
    }

    public static function customer(): array
    {
        return [
            'id' => '456',
            'type' => 'customers',
            'attributes' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'avatar' => null,
                'external_user_id' => 'ext_123',
                'public_bio' => null,
                'public_location' => null,
                'public_website' => null,
                'socials' => null,
                'net_revenue' => '99.00',
                'sign_in_count' => 5,
                'last_request_at' => '2024-01-16T14:20:00Z',
                'bounced_at' => null,
                'created_at' => '2024-01-15T10:30:00Z',
                'updated_at' => '2024-01-16T14:20:00Z',
            ],
        ];
    }

    public static function site(): array
    {
        return [
            'id' => '1',
            'type' => 'sites',
            'attributes' => [
                'name' => 'My Kajabi Site',
                'domain' => 'example.kajabi.com',
                'created_at' => '2023-01-01T00:00:00Z',
                'updated_at' => '2024-01-01T00:00:00Z',
            ],
        ];
    }

    public static function webhook(): array
    {
        return [
            'id' => '999',
            'type' => 'hooks',
            'attributes' => [
                'name' => 'Purchase Created Webhook',
                'event' => 'purchase.created',
                'url' => 'https://example.com/webhooks/kajabi',
                'active' => true,
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2024-01-01T00:00:00Z',
            ],
        ];
    }

    public static function paginatedResponse(array $items, int $page = 1, int $perPage = 25, int $total = null): array
    {
        $total = $total ?? count($items);
        $totalPages = (int) ceil($total / $perPage);

        return [
            'data' => $items,
            'meta' => [
                'total_count' => $total,
                'total_pages' => $totalPages,
                'page' => $page,
                'per_page' => $perPage,
            ],
            'links' => [
                'self' => "https://api.kajabi.com/v1/resource?page[number]={$page}",
                'first' => 'https://api.kajabi.com/v1/resource?page[number]=1',
                'last' => "https://api.kajabi.com/v1/resource?page[number]={$totalPages}",
                'prev' => $page > 1 ? "https://api.kajabi.com/v1/resource?page[number]=" . ($page - 1) : null,
                'next' => $page < $totalPages ? "https://api.kajabi.com/v1/resource?page[number]=" . ($page + 1) : null,
            ],
        ];
    }
}
