<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;

/**
 * A Contact's enrollments: one per course product across the offers they hold
 *
 * Endpoint: GET /contacts/{contact_id}?include=offers
 *
 * Each offer is expanded into its course products (via $courseProducts) and
 * the result de-duplicated by product, so two offers granting one course give
 * one enrollment. Single page: Kajabi returns all of a contact's offers at once.
 */
class GetContactOffers extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    /**
     * @param \Closure(int): array<int, array> $courseProducts Offer id => its course product resources keyed by id
     *        (ProductService::courseProductsForOffer())
     * @param int|null $productId Only this course product (filtered client-side: Kajabi can't filter a contact's offers)
     * @param int|null $offerId Only this offer
     */
    public function __construct(
        private UserInterface $contact,
        private \Closure      $courseProducts,
        private array         $filters = [],
        private ?string       $defaultSiteId = null,
        private ?int          $productId = null,
        private ?int          $offerId = null,
    )
    {
    }

    /**
     * GET /contacts/{id}?include=offers rather than /relationships/offers: the
     * relationship endpoint returns bare identifiers (no titles), the include
     * returns the same offers with their attributes (verified live), in one request.
     */
    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contact->id}";
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Map Thinkific-style filters to Kajabi filters
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'limit':
                    $query['page[size]'] = $value;
                    break;
                case 'page':
                    $query['page[number]'] = $value;
                    break;
                case 'site_id':
                    $query['filter[site_id]'] = $value;
                    break;
                // Paging controls: read (and stripped) by KajabiConnector::paginate()
                case 'start_page':
                case 'max_pages':
                    $query[$key] = $value;
                    break;
                default:
                    // Pass through other filters as-is
                    $query[$key] = $value;
                    break;
            }
        }

        // Add default site_id if provided
        if (!isset($query['filter[site_id]']) && $this->defaultSiteId) {
            $query['filter[site_id]'] = $this->defaultSiteId;
        }

        $query['include'] = 'offers';

        return $query;
    }

    public function createDtoFromResponse($response): array
    {
        $included = [];
        foreach ($response->json('included', []) as $resource) {
            if (($resource['type'] ?? null) === 'offers') {
                $included[$resource['id']] = $resource;
            }
        }

        $enrollments = [];

        // Offers in Kajabi's order, products in each offer's order; an offer
        // without an included resource still expands (its products are looked up by id)
        foreach ($response->json('data.relationships.offers.data', []) as $identifier) {
            $offerId = (int) $identifier['id'];

            if ($this->offerId !== null && $offerId !== $this->offerId) {
                continue;
            }

            foreach (($this->courseProducts)($offerId) as $productId => $product) {
                if ($this->productId !== null && $productId !== $this->productId) {
                    continue;
                }

                $enrollment = Enrollment::fromKajabiOfferProduct(
                    $included[$identifier['id']] ?? $identifier,
                    $product,
                    $this->contact->id,
                    '', // filled from $this->contact
                    '',
                    $this->contact
                );

                // First offer wins; no grant dates here to prefer one over another
                $enrollments[$enrollment->id] ??= $enrollment;
            }
        }

        return array_values($enrollments);
    }
}