<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;

/**
 * Get Enrollments (Offers granted) for a specific Contact
 *
 * Endpoint: GET /contacts/{contact_id}/relationships/offers
 *
 * Returns Enrollment DTOs representing the user's access to offers
 */
class GetContactOffers extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    /**
     * @param int|null $offerId Only this offer (filtered client-side: Kajabi can't filter a contact's offers)
     */
    public function __construct(
        private UserInterface $contact,
        private array         $filters = [],
        private ?string       $defaultSiteId = null,
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

        // The contact's offers in Kajabi's order; an identifier without an included
        // resource still becomes an enrollment (just without a title)
        foreach ($response->json('data.relationships.offers.data', []) as $identifier) {
            if ($this->offerId !== null && (int) $identifier['id'] !== $this->offerId) {
                continue;
            }

            $enrollments[] = Enrollment::fromKajabiOffer(
                $included[$identifier['id']] ?? $identifier,
                $this->contact->id,
                '', // filled from $this->contact
                '',
                $this->contact
            );
        }

        return $enrollments;
    }
}