<?php

namespace Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Requests\Orders\GetOrders;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Requests\Purchases\GetPurchases;

/**
 * Verifies filter translation against the official Kajabi OpenAPI spec v1.1.0.
 * Unsupported Thinkific-style filters must be silently dropped; supported
 * filters must map to the correct Kajabi query parameter.
 */
class FilterTranslationTest extends TestCase
{
    private function sendAndGetQuery(object $request): array
    {
        $mockClient = new MockClient([
            MockResponse::make(['data' => []]),
        ]);

        $connector = (new KajabiConnector())->withMockClient($mockClient);
        $connector->send($request);

        return $mockClient->getLastRequest()->query()->all();
    }

    public function test_get_products_maps_name_cont_to_title_cont(): void
    {
        $query = $this->sendAndGetQuery(new GetProducts([
            'name_cont' => 'marketing',
            'site_id' => '5',
        ]));

        // /v1/products filters on title_cont, not name_cont
        $this->assertSame('marketing', $query['filter[title_cont]'] ?? null);
        $this->assertArrayNotHasKey('filter[name_cont]', $query);
        $this->assertSame('5', $query['filter[site_id]'] ?? null);
    }

    public function test_get_orders_drops_unsupported_date_filters(): void
    {
        $query = $this->sendAndGetQuery(new GetOrders([
            'user_id' => '456',
            'created_at_gte' => '2024-01-01',
            'created_at_lte' => '2024-12-31',
            'site_id' => '5',
        ]));

        // /v1/orders has no date-range filters
        $this->assertSame('456', $query['filter[customer_id]'] ?? null);
        $this->assertArrayNotHasKey('filter[created_at_gte]', $query);
        $this->assertArrayNotHasKey('filter[created_at_lte]', $query);
        $this->assertSame('5', $query['filter[site_id]'] ?? null);
    }

    public function test_get_purchases_drops_unsupported_filters(): void
    {
        $query = $this->sendAndGetQuery(new GetPurchases([
            'query[user_id]' => '456',
            'query[course_id]' => '202',
            'course_id' => '202',
            'query[email]' => 'john@example.com',
            'created_at_gte' => '2024-01-01',
            'site_id' => '5',
        ]));

        // /v1/purchases has no product_id/customer_email/date-range filters
        $this->assertSame('456', $query['filter[customer_id]'] ?? null);
        $this->assertArrayNotHasKey('filter[product_id]', $query);
        $this->assertArrayNotHasKey('filter[customer_email]', $query);
        $this->assertArrayNotHasKey('filter[created_at_gte]', $query);
        $this->assertSame('5', $query['filter[site_id]'] ?? null);
        // offer/customer relationships still requested for DTO enrichment
        $this->assertSame('offer,customer', $query['include'] ?? null);
    }
}
