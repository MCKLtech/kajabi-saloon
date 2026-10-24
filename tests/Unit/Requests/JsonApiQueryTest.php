<?php

namespace Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Exceptions\UnsupportedFilterException;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithOffer;
use WooNinja\KajabiSaloon\Requests\Courses\GetCourses;
use WooNinja\KajabiSaloon\Requests\Courses\GetCourseStructure;
use WooNinja\KajabiSaloon\Requests\CustomFields\GetCustomFields;
use WooNinja\KajabiSaloon\Requests\Customers\GetCustomers;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffers;
use WooNinja\KajabiSaloon\Requests\Orders\GetOrders;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Requests\Purchases\GetPurchases;
use WooNinja\KajabiSaloon\Requests\Sites\GetSites;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhooks;
use WooNinja\KajabiSaloon\Services\KajabiService;

class JsonApiQueryTest extends TestCase
{
    private static function sent(Request $request, bool $strict = false): array
    {
        $mockClient = new MockClient([MockResponse::make(['data' => [], 'meta' => ['total' => 0], 'links' => []])]);
        $connector = (new KajabiConnector())->withMockClient($mockClient);
        $connector->setStrictFilters($strict);
        $connector->send($request);

        return $mockClient->getLastPendingRequest()->query()->all();
    }

    public function test_fluent_helpers(): void
    {
        $query = self::sent(
            (new GetCustomers([], '111'))
                ->fields('customers', 'name', 'contact')
                ->sort('-created_at', 'name')
                ->filter('created_in_last', 7)
                ->filter('updated_since', new \DateTimeImmutable('2026-10-20T14:00:00Z'))
                ->pageSize(500)
        );

        $this->assertSame('name,contact', $query['fields[customers]']);
        $this->assertSame('-created_at,name', $query['sort']);
        $this->assertSame(7, $query['filter[created_in_last]']);
        $this->assertSame('2026-10-20T14:00:00+00:00', $query['filter[updated_since]']);
        $this->assertSame(500, $query['page[size]']);
        $this->assertSame('111', $query['filter[site_id]'], 'defaults kept');
    }

    public function test_include_adds_to_the_default(): void
    {
        $query = self::sent((new GetCourseStructure(1))->include('lessons.media', 'lessons'));

        $this->assertSame('modules,lessons,lessons.media', $query['include']);
        $this->assertSame('customer,tags', self::sent((new GetContact(1))->include('customer', 'tags'))['include']);
    }

    public function test_strict_mode_is_off_by_default(): void
    {
        $query = self::sent(new GetCustomers(['filter[id]' => '1,2']));

        $this->assertSame('1,2', $query['filter[id]'], 'sent as before (and ignored by Kajabi)');
    }

    public function test_strict_mode_refuses_filters_kajabi_ignores(): void
    {
        try {
            self::sent(new GetCustomers(['filter[id]' => '1,2', 'filter[contact_id]' => 3]), strict: true);
            $this->fail('Expected UnsupportedFilterException');
        } catch (UnsupportedFilterException $e) {
            $this->assertSame(['id', 'contact_id'], $e->parameters);
            $this->assertSame(GetCustomers::class, $e->request);
            $this->assertStringContainsString('unfiltered', $e->getMessage());
        }
    }

    public function test_strict_mode_refuses_spec_filters_the_live_api_ignores(): void
    {
        $this->expectException(UnsupportedFilterException::class);
        $this->expectExceptionMessage('status_eq');

        self::sent((new GetProducts())->filter('status_eq', 'draft'), strict: true);
    }

    public function test_strict_mode_refuses_pass_through_non_jsonapi_parameters(): void
    {
        $this->expectException(UnsupportedFilterException::class);
        $this->expectExceptionMessage('query[course_id]');

        self::sent(new GetContacts(['query[course_id]' => 9]), strict: true);
    }

    public function test_strict_mode_accepts_supported_filters_and_array_filters(): void
    {
        $query = self::sent(new GetProducts(['exclude_product_types' => ['A::B', 'C::D'], 'name_cont' => 'Example']), strict: true);
        $this->assertSame('Example', $query['filter[title_cont]']);

        $query = self::sent((new GetCustomers([], '1'))->filter('has_product_id', 5)->fields('customers', 'contact'), strict: true);
        $this->assertSame(5, $query['filter[has_product_id]']);
    }

    public function test_sdk_service_calls_pass_strict_mode(): void
    {
        $mockClient = new MockClient(['*' => MockResponse::make(['data' => [], 'meta' => ['total' => 0], 'links' => []])]);
        $connector = (new KajabiConnector())->withMockClient($mockClient);
        $connector->setStrictFilters();
        $kajabi = new KajabiService('id', 'secret', '111');
        $kajabi->setConnector($connector);
        $kajabi->boot();

        iterator_to_array($kajabi->users->users(['query[email]' => 'a@example.com', 'limit' => 10])->items());
        iterator_to_array($kajabi->customers->customers(['search' => 'a', 'limit' => 10])->items());
        $kajabi->customers->contactIds([1]);
        $kajabi->users->customerIds([1]);
        iterator_to_array($kajabi->courses->courses(['limit' => 10])->items());
        iterator_to_array($kajabi->products->kajabiProducts(['name_cont' => 'a'])->items());
        $kajabi->products->courseProducts();
        iterator_to_array($kajabi->orders->orders(['limit' => 10])->items());
        iterator_to_array($kajabi->enrollments->enrollments(['limit' => 10])->items());
        iterator_to_array($kajabi->webhooks->webhooks()->items());
        $kajabi->courseProgress->page(1, ['customer_id' => 2, 'updated_since' => '2026-01-01T00:00:00Z'], 'abc');

        $this->assertGreaterThan(10, count($mockClient->getRecordedResponses()));
    }

    public function test_paginator_sends_page_params_not_paging_controls(): void
    {
        $mockClient = new MockClient(['*' => MockResponse::make(['data' => [], 'meta' => ['total' => 0], 'links' => []])]);
        $kajabi = new KajabiService('id', 'secret', '111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        foreach ([
            fn() => $kajabi->users->users(['limit' => 50, 'max_pages' => 2, 'start_page' => 3]),
            fn() => $kajabi->offers->offers(['limit' => 50, 'max_pages' => 2, 'start_page' => 3]),
            fn() => $kajabi->enrollments->enrollments(['limit' => 50, 'max_pages' => 2, 'start_page' => 3]),
        ] as $list) {
            iterator_to_array($list()->items());
            $query = $mockClient->getLastPendingRequest()->query()->all();

            $this->assertSame(50, (int) $query['page[size]']);
            $this->assertSame(3, (int) $query['page[number]']);
            foreach (['limit', 'page', 'start_page', 'max_pages'] as $control) {
                $this->assertArrayNotHasKey($control, $query);
            }
        }
    }

    /**
     * Customers, offers, sites, webhooks and purchases used to drop max_pages
     * inside the request, so the paginator never saw it and walked every page
     */
    public function test_max_pages_is_honoured_everywhere(): void
    {
        $page = fn(int $next) => MockResponse::make([
            'data' => [['id' => (string) $next, 'type' => 'x', 'attributes' => [], 'relationships' => []]],
            'meta' => ['total' => 3],
            'links' => ['next' => "https://api.kajabi.com/v1/x?page%5Bnumber%5D={$next}&page%5Bsize%5D=1"],
        ]);

        foreach (['customers', 'offers', 'webhooks'] as $service) {
            $mockClient = new MockClient([$page(2), $page(3), $page(4)]);
            $kajabi = new KajabiService('id', 'secret', '111');
            $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
            $kajabi->boot();

            $method = $service === 'webhooks' ? 'webhooks' : $service;
            iterator_to_array($kajabi->{$service}->{$method}(['limit' => 1, 'max_pages' => 1])->items());

            $this->assertCount(1, $mockClient->getRecordedResponses(), "{$service}: max_pages 1 means one request");
        }
    }

    /**
     * SupportedFilters is generated from openapi.yaml; this fails when the spec changes under it
     */
    public function test_supported_filters_match_the_spec(): void
    {
        $spec = self::specFilters();

        $expected = [
            '/v1/contacts' => GetContacts::supportedFilters(),
            '/v1/customers' => GetCustomers::supportedFilters(),
            '/v1/courses' => GetCourses::supportedFilters(),
            '/v1/custom_fields' => GetCustomFields::supportedFilters(),
            '/v1/offers' => GetOffers::supportedFilters(),
            '/v1/orders' => GetOrders::supportedFilters(),
            '/v1/products' => GetProducts::supportedFilters(),
            '/v1/purchases' => GetPurchases::supportedFilters(),
            '/v1/sites' => GetSites::supportedFilters(),
            '/v1/hooks' => GetWebhooks::supportedFilters(),
        ];

        foreach ($expected as $path => $filters) {
            $ignoredLive = SupportedFilters::IGNORED_LIVE[substr($path, 4)] ?? [];

            $this->assertEqualsCanonicalizing(
                $spec[$path] ?? [],
                [...$filters, ...$ignoredLive],
                "filters for GET {$path} (spec = supported + ignored live)"
            );
        }

        $this->assertSame(GetContacts::supportedFilters(), GetContactsWithOffer::supportedFilters());
    }

    public function test_ignored_lists_unsupported_filters_and_unknown_parameters(): void
    {
        $this->assertSame(
            ['id', 'query[user_id]'],
            SupportedFilters::ignored(
                ['filter[site_id]' => 1, 'filter[id]' => 2, 'query[user_id]' => 3, 'page[size]' => 10, 'include' => 'x', 'fields[a]' => 'b', 'sort' => 'c'],
                ['site_id']
            )
        );
    }

    /**
     * @return array<string, string[]> GET path => filter names
     */
    private static function specFilters(): array
    {
        $filters = [];
        $path = null;
        $inGet = false;

        foreach (file(__DIR__ . '/../../../openapi.yaml') as $line) {
            if (preg_match('/^  "(\/v1\/[^"]+)":\s*$/', $line, $m)) {
                $path = $m[1];
                $inGet = false;
            } elseif (preg_match('/^    (\w+):\s*$/', $line, $m)) {
                $inGet = $m[1] === 'get';
            } elseif ($inGet && $path && preg_match('/^\s+- name: filter\[([^\]]+)\]/', $line, $m)) {
                $filters[$path][] = $m[1];
            }
        }

        return array_map(fn($names) => array_values(array_unique($names)), $filters);
    }
}
