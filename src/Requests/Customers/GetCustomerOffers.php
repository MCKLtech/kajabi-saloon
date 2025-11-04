<?php

namespace WooNinja\KajabiSaloon\Requests\Customers;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;

/**
 * Get Customer's Offers (Enrollments)
 *
 * GET /v1/customers/{customer_id}/relationships/offers
 *
 * Returns the offers (enrollments) granted to a customer.
 * This is the proper way to get enrollments for a user in Kajabi.
 */
class GetCustomerOffers extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private string $customerId,
        private array $filters = []
    ) {}

    public function resolveEndpoint(): string
    {
        return "/customers/{$this->customerId}/relationships/offers";
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Support include parameter to get full offer details
        if (isset($this->filters['include'])) {
            $query['include'] = $this->filters['include'];
        }

        return $query;
    }

    /**
     * Creates array of offer resource identifiers
     * Response format: { "data": [{"type": "offers", "id": "123"}, ...] }
     */
    public function createDtoFromResponse($response): array
    {
        $data = $response->json('data', []);

        // Returns array of offer IDs
        return array_map(fn($item) => $item['id'], $data);
    }
}