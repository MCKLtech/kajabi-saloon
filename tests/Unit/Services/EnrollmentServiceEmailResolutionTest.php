<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\Services\EnrollmentService;
use WooNinja\KajabiSaloon\Services\KajabiService;
use Tests\Fixtures\KajabiApiResponses;

/**
 * Verifies the server-side email -> customer_id resolution in
 * EnrollmentService::enrollments (spec v1.1.0: /v1/purchases has no
 * customer_email filter; the supported path is customer lookup + filter).
 */
class EnrollmentServiceEmailResolutionTest extends TestCase
{
    private function serviceWithMock(MockClient $mockClient): EnrollmentService
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));

        // Construct the enrollment service after the connector is injected so
        // Resource captures the mocked connector.
        return new EnrollmentService($kajabi);
    }

    private function drainPaginator(\Iterator $paginator): void
    {
        foreach ($paginator as $page) {
            // Iterate to trigger the underlying requests
        }
    }

    public function test_enrollments_resolves_query_email_to_customer_id(): void
    {
        $mockClient = new MockClient([
            // customers search (resolve email -> customer 456)
            MockResponse::make(['data' => [KajabiApiResponses::customer()]]),
            // purchases list
            MockResponse::make(['data' => []]),
        ]);

        $enrollments = $this->serviceWithMock($mockClient);

        $paginator = $enrollments->enrollments(['query[email]' => 'john.doe@example.com']);
        $this->drainPaginator($paginator);

        $lastQuery = $mockClient->getLastRequest()->query()->all();
        $this->assertSame(456, $lastQuery['filter[customer_id]'] ?? null);
        $this->assertArrayNotHasKey('filter[customer_email]', $lastQuery);
    }

    public function test_enrollments_accepts_plain_email_key(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['data' => [KajabiApiResponses::customer()]]),
            MockResponse::make(['data' => []]),
        ]);

        $enrollments = $this->serviceWithMock($mockClient);

        $paginator = $enrollments->enrollments(['email' => 'john.doe@example.com']);
        $this->drainPaginator($paginator);

        $lastQuery = $mockClient->getLastRequest()->query()->all();
        $this->assertSame(456, $lastQuery['filter[customer_id]'] ?? null);
    }

    public function test_enrollments_throws_when_no_customer_matches_email(): void
    {
        $mockClient = new MockClient([
            // no customers match the email
            MockResponse::make(['data' => []]),
        ]);

        $enrollments = $this->serviceWithMock($mockClient);

        $this->expectException(\InvalidArgumentException::class);
        $enrollments->enrollments(['query[email]' => 'nobody@example.com']);
    }
}
