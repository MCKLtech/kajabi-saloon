<?php

namespace Tests\Unit\DTOs;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Orders\Order;
use WooNinja\KajabiSaloon\DataTransferObjects\Sites\Site;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use WooNinja\KajabiSaloon\Requests\Orders\GetOrder;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * DTOs against the response shapes the live API actually sends (attribute
 * and relationship sets recorded 2026-10-24; values are made up).
 */
class LiveShapeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function test_site_name_comes_from_title(): void
    {
        $site = Site::fromKajabiSite([
            'id' => '111111',
            'type' => 'sites',
            'attributes' => [
                'title' => 'Example', 'subdomain' => 'example',
                'created_at' => '2020-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z',
            ],
        ]);

        $this->assertSame('Example', $site->name);
        $this->assertSame('example', $site->subdomain);
        $this->assertSame('', $site->domain);
    }

    private static function orderResponse(): array
    {
        return [
            'data' => [
                'id' => '500001',
                'type' => 'orders',
                'attributes' => [
                    'currency_code' => 'CAD', 'fulfilled_at' => '2026-10-01T10:00:00Z', 'created_at' => '2026-10-01T09:59:00Z',
                    'order_number' => 1042, 'total_price_in_cents' => 11300, 'subtotal_in_cents' => 10000,
                    'sales_tax_amount_in_cents' => 1300, 'discount_amount_in_cents' => 0,
                    'formatted_total_price' => 'CA$113.00', 'formatted_subtotal' => 'CA$100.00', 'currency_symbol' => '$',
                ],
                'relationships' => [
                    'site' => ['data' => ['id' => '1', 'type' => 'sites']],
                    'customer' => ['data' => ['id' => '555', 'type' => 'customers']],
                    'order_items' => ['data' => [['id' => '9001', 'type' => 'order_items'], ['id' => '9002', 'type' => 'order_items']]],
                    'payment_transactions' => ['data' => [['id' => '77', 'type' => 'transactions']]],
                ],
            ],
            'included' => [
                [
                    'id' => '9001', 'type' => 'order_items',
                    'attributes' => [
                        'quantity' => 1, 'currency_code' => 'CAD', 'item_type' => 'Offer', 'item_id' => 4000101,
                        'title' => 'Sample Course', 'total_price_in_cents' => 8475, 'purchase_id' => 31,
                    ],
                    'relationships' => ['order' => ['data' => ['id' => '500001', 'type' => 'orders']]],
                ],
                [
                    'id' => '9002', 'type' => 'order_items',
                    'attributes' => [
                        'quantity' => 1, 'currency_code' => 'CAD', 'item_type' => 'Offer', 'item_id' => 4000102,
                        'title' => 'Bonus', 'total_price_in_cents' => 2825, 'purchase_id' => 32,
                    ],
                ],
                [
                    'id' => '555', 'type' => 'customers',
                    'attributes' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
                ],
            ],
        ];
    }

    public function test_order_maps_cents_items_and_customer(): void
    {
        $body = self::orderResponse();
        $order = Order::fromKajabiOrder($body['data'], Order::includedMap($body['included']));

        $this->assertSame(11300, $order->amount_cents);
        $this->assertSame(113.0, $order->amount_dollars);
        $this->assertSame('CAD', $order->currency);
        $this->assertSame(1042, $order->order_number);
        $this->assertSame(10000, $order->subtotal_cents);
        $this->assertSame(1300, $order->sales_tax_cents);
        $this->assertSame('completed', $order->status, 'fulfilled');
        $this->assertSame(555, $order->user_id);
        $this->assertSame('ada@example.com', $order->user_email);
        $this->assertSame('Ada Lovelace', $order->user_name);
        $this->assertSame(4000101, $order->product_id, 'the first item\'s offer');
        $this->assertSame('Sample Course', $order->product_name);
        $this->assertCount(2, $order->items);
        $this->assertSame(['id' => 4000102, 'name' => 'Bonus', 'amount' => 28.25, 'type' => 'Offer', 'quantity' => 1, 'purchase_id' => 32, 'order_item_id' => 9002], $order->items[1]);
    }

    public function test_unfulfilled_order_is_pending(): void
    {
        $body = self::orderResponse();
        $body['data']['attributes']['fulfilled_at'] = null;

        $this->assertSame('pending', Order::fromKajabiOrder($body['data'])->status);
    }

    public function test_get_order_requests_items_and_customer(): void
    {
        $mockClient = new MockClient([MockResponse::make(self::orderResponse())]);
        $kajabi = new KajabiService('id', 'secret', '1');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        $order = $kajabi->orders->get(500001);

        $this->assertSame('order_items,customer', $mockClient->getLastPendingRequest()->query()->get('include'));
        $this->assertSame('ada@example.com', $order->user_email);
        $this->assertInstanceOf(GetOrder::class, $mockClient->getLastPendingRequest()->getRequest());
    }

    private static function purchase(?string $deactivatedAt): array
    {
        return [
            'id' => '600001',
            'type' => 'purchases',
            'attributes' => [
                'amount_in_cents' => 0, 'payment_type' => 'free', 'currency' => 'USD',
                'effective_start_at' => '2026-01-01T00:00:00Z', 'deactivated_at' => $deactivatedAt,
                'deactivation_reason' => $deactivatedAt ? 'admin' : null, 'source' => 'admin',
                'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z',
            ],
            'relationships' => [
                'customer' => ['data' => ['id' => '555', 'type' => 'customers']],
                'offer' => ['data' => ['id' => '700', 'type' => 'offers']],
                'products' => ['data' => [['id' => '1', 'type' => 'products']]],
            ],
        ];
    }

    public function test_purchase_with_past_deactivation_is_expired(): void
    {
        Carbon::setTestNow('2026-10-24T00:00:00Z');

        $enrollment = Enrollment::fromKajabiPurchase(self::purchase('2026-06-01T00:00:00Z'));

        $this->assertTrue($enrollment->expired);
        $this->assertSame('deactivated', $enrollment->status);
        $this->assertTrue(Carbon::parse('2026-06-01T00:00:00Z')->eq($enrollment->expiry_date));
    }

    public function test_purchase_with_scheduled_deactivation_is_still_active(): void
    {
        Carbon::setTestNow('2026-10-24T00:00:00Z');

        $enrollment = Enrollment::fromKajabiPurchase(self::purchase('2027-01-01T00:00:00Z'));

        $this->assertFalse($enrollment->expired, 'filter[active]=true returns these');
        $this->assertSame('active', $enrollment->status);
        $this->assertTrue(Carbon::parse('2027-01-01T00:00:00Z')->eq($enrollment->expiry_date));
    }

    public function test_purchase_without_deactivation(): void
    {
        $enrollment = Enrollment::fromKajabiPurchase(self::purchase(null));

        $this->assertFalse($enrollment->expired);
        $this->assertNull($enrollment->expiry_date);
    }

    public function test_contact_external_user_id(): void
    {
        $user = User::fromKajabiContact([
            'id' => '1', 'type' => 'contacts',
            'attributes' => ['name' => 'A B', 'email' => 'a@example.com', 'external_user_id' => 'ext-9'],
        ]);

        $this->assertSame('ext-9', $user->external_user_id);
    }

    public function test_paginator_totals_without_meta_come_from_links_last(): void
    {
        $offer = ['id' => '1', 'type' => 'offers', 'attributes' => ['title' => 'x', 'description' => null], 'relationships' => []];
        $mockClient = new MockClient(['*' => MockResponse::make([
            'data' => [$offer],
            'links' => [
                'self' => 'https://api.kajabi.com/v1/offers?page%5Bnumber%5D=1&page%5Bsize%5D=1',
                'next' => 'https://api.kajabi.com/v1/offers?page%5Bnumber%5D=2&page%5Bsize%5D=1',
                'last' => 'https://api.kajabi.com/v1/offers?page%5Bnumber%5D=161&page%5Bsize%5D=1',
            ],
        ])]);
        $kajabi = new KajabiService('id', 'secret', '1');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        $offers = $kajabi->offers->offers(['limit' => 1, 'max_pages' => 1]);
        iterator_to_array($offers->items());

        $this->assertFalse($offers->hasTotalAPIResults(), 'offers send no meta');
        $this->assertSame(0, $offers->getTotalAPIResults());
        $this->assertSame(161, $offers->getTotalAPIPages());
    }

    public function test_paginator_totals_with_meta(): void
    {
        $mockClient = new MockClient(['*' => MockResponse::make([
            'data' => [['id' => '1', 'type' => 'customers', 'attributes' => [], 'relationships' => []]],
            'meta' => ['total' => 2500],
            'links' => ['last' => 'https://api.kajabi.com/v1/customers?page%5Bnumber%5D=3&page%5Bsize%5D=1000'],
        ])]);
        $kajabi = new KajabiService('id', 'secret', '1');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        $customers = $kajabi->customers->customers(['limit' => 1000]);
        iterator_to_array($customers->items());

        $this->assertTrue($customers->hasTotalAPIResults());
        $this->assertSame(2500, $customers->getTotalAPIResults());
        $this->assertSame(3, $customers->getTotalAPIPages());
    }
}
