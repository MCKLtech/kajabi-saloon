<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

/**
 * Contacts with access to a Product, by any offer, as Enrollments (one per contact)
 *
 * Endpoint: GET /contacts?filter[has_product_id]=X
 */
class GetContactsWithProduct extends GetContactsWithOffer
{
    /**
     * @param array $product The product resource (JSON:API data), for the enrollments' course_name
     */
    public function __construct(
        array $product,
        array $filters = [],
        ?string $defaultSiteId = null,
    ) {
        parent::__construct(0, [(int) $product['id'] => $product], $filters, $defaultSiteId);
    }

    protected function membershipFilter(): array
    {
        return ['filter[has_product_id]' => (string) array_key_first($this->courseProducts)];
    }
}
