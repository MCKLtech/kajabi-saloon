<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\Requests\Concerns\JsonApiQuery;
use WooNinja\KajabiSaloon\Requests\Concerns\SupportedFilters;
use WooNinja\KajabiSaloon\Requests\Contracts\DeclaresSupportedFilters;

/**
 * Contacts who hold an Offer, as Enrollments: one per contact per course product
 *
 * Endpoint: GET /contacts?filter[has_offer_id]=X
 *
 * Used for a bundle's (offer's) enrollments. A page of N contacts yields
 * N x (course products in the offer) enrollments.
 */
class GetContactsWithOffer extends Request implements Paginatable, DeclaresSupportedFilters
{
    use JsonApiQuery;

    protected Method $method = Method::GET;

    /**
     * @param array<int, array> $courseProducts The offer's course product resources keyed by id
     *        (ProductService::courseProductsForOffer())
     */
    public function __construct(
        protected int $offerId,
        protected array $courseProducts,
        protected array $filters = [],
        protected ?string $defaultSiteId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/contacts';
    }

    protected function defaultQuery(): array
    {
        $query = $this->membershipFilter();

        // Don't include offers - it causes timeouts with large datasets
        // We already know the offer_id from the constructor

        // Map other filters (pass through as-is)
        foreach ($this->filters as $key => $value) {
            if ($key !== 'offer_id' && $key !== 'product_id') { // Don't override our filter
                $query[$key] = $value;
            }
        }

        // Add default site_id if provided
        if (!isset($query['filter[site_id]']) && $this->defaultSiteId) {
            $query['filter[site_id]'] = $this->defaultSiteId;
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    protected function membershipFilter(): array
    {
        return ['filter[has_offer_id]' => (string) $this->offerId];
    }

    public function createDtoFromResponse($response): array
    {
        $offer = ['id' => (string) $this->offerId, 'type' => 'offers'];
        $enrollments = [];

        foreach ($response->json('data', []) as $contact) {
            foreach ($this->courseProducts as $product) {
                $enrollments[] = Enrollment::fromKajabiOfferProduct(
                    $offer,
                    $product,
                    (int) $contact['id'],
                    $contact['attributes']['email'] ?? '',
                    $contact['attributes']['name'] ?? ''
                );
            }
        }

        return $enrollments;
    }

    public static function supportedFilters(): array
    {
        return SupportedFilters::CONTACTS;
    }
}
