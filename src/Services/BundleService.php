<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Bundles\Bundle;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOfferIds;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOffers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\Requests\Offers\GrantOffer;
use WooNinja\KajabiSaloon\Requests\Products\GetCourseProducts;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\BundleInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Bundles\CreateBundleEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\Services\BundleServiceInterface;

/**
 * Bundles are Kajabi OFFERS: an offer grants one or more products, and its
 * course products are the bundle's courses
 *
 * - productable_id = Offer ID, user_id = Contact ID
 * - Holding the offer = enrolled in each of its course products (an Enrollment
 *   per product, id "{productId}:{contactId}", never expired: Kajabi has no
 *   partial or expired state on a held offer)
 * - activated_at / expiry_date are ignored: Kajabi offers have no dates
 */
class BundleService extends Resource implements BundleServiceInterface
{
    /**
     * The offer as a bundle; course_ids are its course products
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function get(int $productable_id): BundleInterface
    {
        $offer = $this->connector->send(new GetOffer($productable_id))->json('data');

        return Bundle::fromKajabiOffer(
            $offer,
            array_keys($this->products()->courseProductsForOffer($productable_id, $offer))
        );
    }

    /**
     * The offer's course products as Course DTOs
     */
    public function courses(int $productable_id): Paginator
    {
        return $this->connector->paginate(new GetCourseProducts(
            ['limit' => 100],
            $this->getDefaultSiteId(),
            $this->products()->courseProductIdsForOffer($productable_id)
        ));
    }

    /**
     * Contacts holding the offer, one Enrollment per contact per course product
     *
     * API: GET /v1/contacts?filter[has_offer_id]={productable_id}
     */
    public function enrollments(int $productable_id, array $filters = []): Paginator
    {
        return $this->connector->paginate(new GetContactsWithOffer(
            $productable_id,
            $this->products()->courseProductsForOffer($productable_id),
            $filters,
            $this->getDefaultSiteId()
        ));
    }

    /**
     * The contact's enrollments in the offer's course products; [] when they don't hold it
     *
     * @param array $filters Only site_id is used: a contact's offers come back in one request
     * @return Enrollment[]
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function enrollmentsForUser(int $productable_id, int|string $user_id_or_email, array $filters = []): array
    {
        $contact = $this->resolveContact($user_id_or_email);

        if ($contact === null) {
            return [];
        }

        $products = $this->products();

        return $this->connector->send(new GetContactOffers(
            $contact,
            fn(int $offerId) => $products->courseProductsForOffer($offerId),
            array_intersect_key($filters, ['site_id' => true]),
            $this->getDefaultSiteId(),
            offerId: $productable_id
        ))->dtoOrFail();
    }

    /**
     * Grant the offer to the contact
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function createEnrollment(CreateBundleEnrollmentInterface $createBundleEnrollment): Response
    {
        return $this->grant($createBundleEnrollment->productable_id, $createBundleEnrollment->user_id);
    }

    /**
     * Extend or restore access: Kajabi has no expiry, so a held offer needs no
     * change and the contact lookup's response is returned; an offer that isn't
     * held (e.g. after expireEnrollment()) is granted again
     *
     * @param \WooNinja\LMSContracts\Contracts\DTOs\Bundles\UpdateBundleEnrollmentInterface $updateBundleEnrollment
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function updateEnrollment($updateBundleEnrollment): Response
    {
        $offerId = (int) $updateBundleEnrollment->productable_id;
        $contactId = (int) $updateBundleEnrollment->user_id;

        $held = $this->connector->send(new GetContactOfferIds($contactId));

        if (in_array($offerId, $held->dtoOrFail(), true)) {
            return $held;
        }

        return $this->grant($offerId, $contactId);
    }

    /**
     * Revoke the offer from the contact
     *
     * Idempotent: an offer that isn't held returns the contact lookup's response
     * instead of Kajabi's 400. Other errors (unknown contact, server errors, ...) throw.
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function expireEnrollment(int $productable_id, int $user_id): Response
    {
        return $this->revokeOffer($user_id, $productable_id);
    }

    /**
     * Whether the contact holds the offer and it grants at least one course
     */
    public function isUserEnrolled(int $productable_id, int|string $user_id_or_email, array $filters = []): bool
    {
        return $this->enrollmentsForUser($productable_id, $user_id_or_email, $filters) !== [];
    }

    /**
     * Same as isUserEnrolled(): a held offer is always active (Thinkific parity)
     */
    public function hasActiveEnrollment(int $productable_id, int|string $user_id_or_email, array $filters = []): bool
    {
        return $this->isUserEnrolled($productable_id, $user_id_or_email, $filters);
    }

    /**
     * Same as isUserEnrolled(): Kajabi keeps no expired enrollments (Thinkific parity)
     */
    public function hasAnyEnrollment(int $productable_id, int|string $user_id_or_email, array $filters = []): bool
    {
        return $this->isUserEnrolled($productable_id, $user_id_or_email, $filters);
    }

    private function grant(int $offerId, int $contactId): Response
    {
        return $this->connector->send(
            new GrantOffer((string) $contactId, (string) $offerId, $this->getDefaultSiteId(), false)
        );
    }

    private function products(): ProductService
    {
        return new ProductService($this->kajabi);
    }
}
