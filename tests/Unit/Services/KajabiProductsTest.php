<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Products\Product;
use WooNinja\KajabiSaloon\Requests\Products\GetProducts;
use WooNinja\KajabiSaloon\Services\KajabiService;

/**
 * Real Kajabi products (GET /v1/products), as opposed to the offers
 * ProductService::products() returns for Thinkific compatibility.
 */
class KajabiProductsTest extends TestCase
{
    /**
     * Live product shape (attribute set verified against the API)
     */
    private static function product(int $id, string $type, int $typeId, int $members = 0): array
    {
        return [
            'id' => (string) $id,
            'type' => 'products',
            'attributes' => [
                'created_at' => '2024-01-01T00:00:00Z',
                'title' => "Product {$id}",
                'description' => null,
                'status' => 'ready',
                'members_aggregate_count' => $members,
                'product_type_name' => $type,
                'product_type_id' => $typeId,
                'publish_status' => 'published',
                'thumbnail_url' => 'https://example.com/t.jpg',
                'url' => 'https://example.com/p',
            ],
        ];
    }

    private function kajabi(MockClient $mockClient): KajabiService
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', '111111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));
        $kajabi->boot();

        return $kajabi;
    }

    private static function query(array $filters): array
    {
        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        (new KajabiConnector())->withMockClient($mockClient)->send(new GetProducts($filters, '111111'));

        return $mockClient->getLastPendingRequest()->query()->all();
    }

    public function test_product_type_fields(): void
    {
        $course = Product::fromKajabiProduct(self::product(2000101, 'Course', 2000101, 120));
        $community = Product::fromKajabiProduct(self::product(2000202, 'AccessGroup', 3000303, 30));

        $this->assertSame('Course', $course->product_type_name);
        $this->assertSame(2000101, $course->product_type_id);
        $this->assertSame(120, $course->members_aggregate_count);
        $this->assertSame('published', $course->publish_status);
        $this->assertSame('Product 2000101', $course->name, 'title');
        $this->assertSame('https://example.com/t.jpg', $course->card_image_url, 'thumbnail_url');
        $this->assertTrue($course->isCourse());
        $this->assertFalse($community->isCourse());
    }

    public function test_product_filters(): void
    {
        $query = self::query(['name_cont' => 'Example', 'description_cont' => 'x']);

        $this->assertSame('Example', $query['filter[title_cont]']);
        $this->assertSame('x', $query['filter[description_cont]']);
        $this->assertSame('111111', $query['filter[site_id]']);
    }

    public function test_exclude_product_types(): void
    {
        $this->assertSame(
            GetProducts::COHORT_COURSE,
            self::query(['exclude_product_types' => GetProducts::COHORT_COURSE])['filter[exclude_product_types][]']
        );
        $this->assertSame(
            ['Courses::CohortCourse', 'Other::Type'],
            self::query(['exclude_product_types' => ['Courses::CohortCourse', 'Other::Type']])['filter[exclude_product_types]']
        );
        $this->assertArrayNotHasKey('filter[exclude_product_types]', self::query(['exclude_product_types' => []]));
    }

    public function test_course_products_keeps_only_courses_and_excludes_cohorts(): void
    {
        $mockClient = new MockClient([MockResponse::make(['data' => [
            self::product(1, 'Course', 1),
            self::product(2, 'AccessGroup', 99),
            self::product(3, 'CoachingProgram', 98),
            self::product(4, 'Course', 4),
        ], 'meta' => ['total' => 4], 'links' => []])]);

        $products = $this->kajabi($mockClient)->products->courseProducts();

        $this->assertSame([1, 4], array_map(fn(Product $p) => $p->id, $products));
        $query = $mockClient->getLastPendingRequest()->query()->all();
        $this->assertSame(GetProducts::COHORT_COURSE, $query['filter[exclude_product_types][]']);
        $this->assertSame('/products', $mockClient->getLastPendingRequest()->getRequest()->resolveEndpoint());
    }

    public function test_kajabi_product_by_id(): void
    {
        $mockClient = new MockClient([MockResponse::make(['data' => self::product(7, 'Course', 7)])]);

        $product = $this->kajabi($mockClient)->products->kajabiProduct(7);

        $this->assertSame(7, $product->id);
        $this->assertSame('/products/7', $mockClient->getLastPendingRequest()->getRequest()->resolveEndpoint());
    }
}
