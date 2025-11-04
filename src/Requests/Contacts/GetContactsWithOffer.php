<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;

/**
 * Get Contacts who have been granted a specific Offer (returns as Enrollments)
 *
 * Endpoint: GET /contacts?filter[has_offer_id]=X
 *
 * This is used for finding all enrollments in a specific course (offer).
 * Returns Enrollment DTOs representing users enrolled in the offer.
 */
class GetContactsWithOffer extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function __construct(
        private int $offerId,
        private array $filters = [],
        private ?string $defaultSiteId = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/contacts';
    }

    protected function defaultQuery(): array
    {
        $query = [];

        // Add the offer filter
        $query['filter[has_offer_id]'] = (string) $this->offerId;

        // Don't include offers - it causes timeouts with large datasets
        // We already know the offer_id from the constructor

        // Map other filters (pass through as-is)
        foreach ($this->filters as $key => $value) {
            if ($key !== 'offer_id') { // Don't override our filter
                $query[$key] = $value;
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

        // We already know the offer ID from the filter we applied
        // Create minimal offer data for the Enrollment DTO
        $offerData = [
            'id' => (string) $this->offerId,
            'type' => 'offers',
            'attributes' => [
                'title' => 'Offer ' . $this->offerId,
                'price_in_cents' => 0,
                'subscription' => false,
            ]
        ];

        // Transform contacts to enrollments
        return array_map(function ($contact) use ($offerData) {
            $contactId = (int) $contact['id'];
            $email = $contact['attributes']['email'] ?? '';
            $name = $contact['attributes']['name'] ?? '';

            // Create enrollment from minimal offer data and contact info
            return Enrollment::fromKajabiOffer(
                $offerData,
                $contactId,
                $email,
                $name
            );
        }, $data);
    }
}