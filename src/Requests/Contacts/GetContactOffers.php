<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;

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

    public function __construct(
        private string $contactId,
        private array $filters = [],
        private ?string $defaultSiteId = null
    ) {}

    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contactId}/relationships/offers";
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Map filters
        foreach ($this->filters as $key => $value) {
            switch ($key) {
                case 'site_id':
                    $query['filter[site_id]'] = $value;
                    break;
                default:
                    $query[$key] = $value;
                    break;
            }
        }

        // Add default site_id if provided
        if (!isset($query['filter[site_id]']) && $this->defaultSiteId) {
            $query['filter[site_id]'] = $this->defaultSiteId;
        }

        return $query;
    }

    public function createDtoFromResponse($response): array
    {
        $data = $response->json('data', []);

        // Transform offers to enrollments
        // Note: We don't have contact details here, so we set basic info
        return array_map(function ($offer) {
            return Enrollment::fromKajabiOffer(
                $offer,
                (int)$this->contactId,
                '', // email not available in this response
                ''  // name not available in this response
            );
        }, $data);
    }
}