<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Services\CustomerService;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * customer_id <=> contact_id maps built from the list endpoints.
 *
 * Shapes match live responses to GET /customers?fields[customers]=contact and
 * GET /contacts?fields[contacts]=customer: no attributes, only the named
 * relationship, and meta.total + links.next for paging.
 */
class IdMappingTest extends TestCase
{
    private function kajabi(MockClient $mockClient): KajabiService
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', '111111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        return $kajabi;
    }

    /**
     * @param array<int, int|null> $rows id => related id
     */
    private static function page(string $type, string $relationship, string $relatedType, array $rows, int $total, ?int $nextPage = null): array
    {
        $data = [];

        foreach ($rows as $id => $relatedId) {
            $data[] = [
                'id' => (string) $id,
                'type' => $type,
                'relationships' => [
                    $relationship => ['data' => $relatedId === null ? null : ['id' => (string) $relatedId, 'type' => $relatedType]],
                ],
            ];
        }

        $links = ['self' => "https://api.kajabi.com/v1/{$type}?page%5Bnumber%5D=1&page%5Bsize%5D=1000"];

        if ($nextPage !== null) {
            $links['next'] = "https://api.kajabi.com/v1/{$type}?filter%5Bsite_id%5D=111111&page%5Bnumber%5D={$nextPage}&page%5Bsize%5D=1000";
        }

        return ['data' => $data, 'meta' => ['total' => $total], 'links' => $links];
    }

    private static function customersPage(array $rows, int $total = 4, ?int $nextPage = null): MockResponse
    {
        return MockResponse::make(self::page('customers', 'contact', 'contacts', $rows, $total, $nextPage));
    }

    private static function contactsPage(array $rows, int $total = 4, ?int $nextPage = null): MockResponse
    {
        return MockResponse::make(self::page('contacts', 'customer', 'customers', $rows, $total, $nextPage));
    }

    public function test_contact_ids_maps_requested_customers_and_asks_only_for_the_relationship(): void
    {
        $mockClient = new MockClient([
            self::customersPage([11 => 101, 12 => 102], nextPage: 2),
            self::customersPage([13 => 103, 14 => 104]),
        ]);

        $map = $this->kajabi($mockClient)->customers->contactIds([14, 11]);

        $this->assertSame([11 => 101, 14 => 104], $map);

        $query = $mockClient->getRecordedResponses()[0]->getPendingRequest()->query()->all();
        $this->assertSame('contact', $query['fields[customers]'], 'sparse fieldsets drop unnamed relationships');
        $this->assertSame(CustomerService::ID_MAP_PAGE_SIZE, (int) $query['page[size]']);
        $this->assertSame('111111', $query['filter[site_id]']);
        $this->assertArrayNotHasKey('filter[id]', $query, 'Kajabi ignores filter[id]');
    }

    public function test_contact_ids_stops_once_every_id_is_found(): void
    {
        $mockClient = new MockClient([
            self::customersPage([11 => 101, 12 => 102], nextPage: 2),
            self::customersPage([13 => 103, 14 => 104]),
        ]);

        $this->assertSame([12 => 102], $this->kajabi($mockClient)->customers->contactIds(['12']));

        $mockClient->assertSentCount(1);
    }

    public function test_contact_ids_leaves_out_unknown_ids(): void
    {
        $mockClient = new MockClient([
            self::customersPage([11 => 101, 12 => 102], nextPage: 2),
            self::customersPage([13 => 103, 14 => 104]),
        ]);

        $this->assertSame([13 => 103], $this->kajabi($mockClient)->customers->contactIds([13, 999]));

        $mockClient->assertSentCount(2);
    }

    public function test_contact_ids_with_no_ids_sends_nothing(): void
    {
        $mockClient = new MockClient([]);

        $this->assertSame([], $this->kajabi($mockClient)->customers->contactIds([]));

        $mockClient->assertNothingSent();
    }

    public function test_contact_id_map_covers_every_customer(): void
    {
        $mockClient = new MockClient([
            self::customersPage([11 => 101, 12 => 102], nextPage: 2),
            self::customersPage([13 => 103, 14 => 104]),
        ]);

        $this->assertSame(
            [11 => 101, 12 => 102, 13 => 103, 14 => 104],
            $this->kajabi($mockClient)->customers->contactIdMap()
        );
    }

    public function test_customer_ids_maps_contacts_and_skips_non_customers(): void
    {
        $mockClient = new MockClient([
            self::contactsPage([101 => 11, 105 => null], nextPage: 2),
            self::contactsPage([103 => 13, 104 => 14]),
        ]);

        $map = $this->kajabi($mockClient)->users->customerIds([105, 103, 101]);

        $this->assertSame([101 => 11, 103 => 13], $map, 'a contact with a null customer relationship is not a customer yet');

        $query = $mockClient->getRecordedResponses()[0]->getPendingRequest()->query()->all();
        $this->assertSame('customer', $query['fields[contacts]']);
        $this->assertSame(CustomerService::ID_MAP_PAGE_SIZE, (int) $query['page[size]']);
    }

    public function test_customer_ids_stops_once_every_id_is_seen(): void
    {
        $mockClient = new MockClient([
            self::contactsPage([101 => 11, 105 => null], nextPage: 2),
            self::contactsPage([103 => 13]),
        ]);

        $this->assertSame([], $this->kajabi($mockClient)->users->customerIds([105]));

        $mockClient->assertSentCount(1);
    }
}
