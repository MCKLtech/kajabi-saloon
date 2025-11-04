<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;
use Saloon\Exceptions\Request\RequestException;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use Saloon\Exceptions\Request\FatalRequestException;
use WooNinja\KajabiSaloon\Requests\Offers\GrantOffer;
use WooNinja\KajabiSaloon\Requests\Offers\RevokeOffer;
use WooNinja\KajabiSaloon\Requests\Purchases\GetPurchases;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOffers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithOffer;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\LMSContracts\Contracts\Services\EnrollmentServiceInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\EnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\ReadEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\UpdateEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\CreateEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\DeleteEnrollmentInterface;

/**
 * Enrollment Service for Kajabi
 *
 * CRITICAL ARCHITECTURE:
 * - Works exclusively with CONTACTS (user_id = Contact ID)
 * - Offers are granted DIRECTLY to Contacts (not Customers)
 * - Kajabi automatically creates Customer records when offers are granted
 * - Granting an Offer creates the enrollment
 * - We return Offer data formatted as Enrollments
 *
 * KEY CONCEPTS:
 * 1. Grant Offer: POST /v1/contacts/{contact_id}/relationships/offers
 * 2. Revoke Offer: DELETE /v1/contacts/{contact_id}/relationships/offers
 *
 * ENROLLMENT FLOW:
 * 1. Dashboard provides user_id (Contact ID) and course_id (Offer ID)
 * 2. Grant Offer directly to Contact
 * 3. Return Offer data formatted as Enrollment
 *
 * INTERFACE IMPLEMENTATION:
 * - All methods strictly implement EnrollmentServiceInterface
 * - user_id = Contact ID
 * - course_id = Offer ID
 * - enrollment = Offer granted to Contact
 *
 * This service ONLY uses EnrollmentServiceInterface methods.
 */
class EnrollmentService extends Resource implements EnrollmentServiceInterface
{
    /**
     * Get an Enrollment by ID
     *
     * Uses the user_id (Contact ID) and course_id (Offer ID) from ReadEnrollmentInterface
     * to check if the contact has access to the offer, and returns it as an enrollment.
     *
     * @param ReadEnrollmentInterface $enrollment_id
     * @return EnrollmentInterface
     * @throws \InvalidArgumentException if contact doesn't have access to offer
     */
    public function get(ReadEnrollmentInterface $enrollment_id): EnrollmentInterface
    {
        $contactId = $enrollment_id->user_id;
        $offerId = $enrollment_id->course_id;

        // Check if user is enrolled in this course (has access to this offer)
        if (!$this->isUserEnrolledInCourse($contactId, $offerId)) {
            throw new \InvalidArgumentException(
                "Contact {$contactId} does not have access to offer {$offerId}"
            );
        }

        // Get contact details
        $userService = new UserService($this->kajabi);
        $contact = $userService->get($contactId);

        if ($contact === null) {
            throw new \InvalidArgumentException("Contact ID {$contactId} not found.");
        }

        // Fetch the offer details
        $offerResponse = $this->connector->send(new GetOffer($offerId));
        $offerData = $offerResponse->json('data');

        // Return offer as enrollment
        return Enrollment::fromKajabiOffer(
            $offerData,
            $contact->id,
            $contact->email,
            $contact->first_name . ' ' . $contact->last_name
        );
    }

    /**
     * List enrollments (Purchases)
     *
     * @param array $filters
     * @return Paginator
     */
    public function enrollments(array $filters = []): Paginator
    {
        return $this->connector
            ->paginate(new GetPurchases($filters, $this->getDefaultSiteId()));
    }

    /**
     * Create an Enrollment (grants an OFFER to a CONTACT)
     *
     * CRITICAL FLOW:
     * 1. Receive user_id (Contact ID) and course_id (Offer ID)
     * 2. Get Contact details
     * 3. Grant Offer to Contact via POST /v1/contacts/{contact_id}/relationships/offers
     * 4. Fetch the Offer details and return as Enrollment
     *
     * @param CreateEnrollmentInterface $enrollment user_id=Contact ID, course_id=Offer ID
     * @return EnrollmentInterface
     * @throws FatalRequestException
     * @throws RequestException|\JsonException
     */
    public function create(CreateEnrollmentInterface $enrollment): EnrollmentInterface
    {
        $offerId = $enrollment->course_id;  // course_id is Offer ID
        $contactId = $enrollment->user_id;  // user_id is Contact ID

        // Get contact details to include in enrollment response
        $userService = new UserService($this->kajabi);
        $contact = $userService->get($contactId);

        if ($contact === null) {
            throw new \InvalidArgumentException("Contact ID {$contactId} not found.");
        }

        // Grant the offer directly to the contact
        $response = $this->connector->send(
            new GrantOffer(
                (string)$contactId,
                (string)$offerId,
                $this->getDefaultSiteId(),
                false // Don't send welcome email by default
            )
        );

        if (!$response->successful()) {
            throw new \Exception('Failed to grant offer to contact');
        }

        // Fetch the offer details to return as enrollment
        $offerResponse = $this->connector->send(new GetOffer($offerId));
        $offerData = $offerResponse->json('data');

        // Create enrollment from offer data
        return Enrollment::fromKajabiOffer(
            $offerData,
            $contact->id,
            $contact->email,
            $contact->first_name . ' ' . $contact->last_name
        );
    }

    /**
     * Update an Enrollment
     *
     * Checks the expiry_date in UpdateEnrollmentInterface:
     * - If expiry_date is in the past, revoke the offer (unenroll)
     * - If expiry_date is in the future or null, grant the offer (enroll)
     *
     * @param UpdateEnrollmentInterface $enrollment
     * @return Response
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function update(UpdateEnrollmentInterface $enrollment): Response
    {
        // Get contact_id and offer_id
        $contactId = $enrollment->user_id;
        $offerId = $enrollment->course_id;

        if ($contactId === null || $offerId === null) {
            throw new \InvalidArgumentException(
                'UpdateEnrollmentInterface must provide both user_id (Contact ID) and course_id (Offer ID) for Kajabi'
            );
        }

        // Determine if we should enroll or unenroll based on expiry_date
        $shouldUnenroll = $enrollment->expiry_date !== null && $enrollment->expiry_date->isPast();

        if ($shouldUnenroll) {
            // Revoke the offer from the contact
            return $this->connector->send(
                new RevokeOffer((string)$contactId, (string)$offerId, $this->getDefaultSiteId())
            );
        } else {
            // Grant the offer to the contact
            return $this->connector->send(
                new GrantOffer((string)$contactId, (string)$offerId, $this->getDefaultSiteId(), false)
            );
        }
    }

    /**
     * Expire an Enrollment (revokes the OFFER from CONTACT)
     *
     * @param DeleteEnrollmentInterface $enrollment_id
     * @return Response
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function expire(DeleteEnrollmentInterface $enrollment_id): Response
    {
        $contactId = $enrollment_id->user_id;
        $offerId = $enrollment_id->course_id;

        // Revoke the offer from the contact
        return $this->connector->send(
            new RevokeOffer((string)$contactId, (string)$offerId, $this->getDefaultSiteId())
        );
    }

    /**
     * Find enrollments for a given Course (Offer)
     *
     * Uses Kajabi's Contacts API with filter[has_offer_id] to find all contacts
     * who have been granted this offer. Returns them as Enrollment DTOs.
     *
     * API: GET /v1/contacts?filter[has_offer_id]={course_id}
     *
     * @param int $course_id Offer ID
     * @param array $filters
     * @return Paginator
     */
    public function enrollmentsForCourse(int $course_id, array $filters = []): Paginator
    {
        return $this->connector->paginate(
            new GetContactsWithOffer(
                $course_id,
                $filters,
                $this->getDefaultSiteId()
            )
        );
    }

    /**
     * Find Enrollments for a given User (Contact)
     *
     * Uses GET /v1/contacts/{contact_id}/relationships/offers
     *
     * @param int|string $user_id_or_email Contact ID or email
     * @param array $filters
     * @return Paginator
     */
    public function enrollmentsForUser(int|string $user_id_or_email, array $filters = []): Paginator
    {
        // Get contact ID
        $contactId = $user_id_or_email;

        // If email provided, find the contact first
        if (!is_numeric($user_id_or_email)) {
            $userService = new UserService($this->kajabi);
            $contact = $userService->findByEmail($user_id_or_email);

            if ($contact === null) {
                // Return empty paginator
                return $this->enrollments([
                    'filter[customer_id]' => 0,
                    'filter[created_at_gte]' => '2999-12-31T23:59:59Z',
                    'page[size]' => 1
                ]);
            }

            $contactId = $contact->id;
        }


        // Use the Contact → Offers relationship endpoint
        return $this->connector->paginate(
            new GetContactOffers(
                (string)$contactId,
                $filters,
                $this->getDefaultSiteId()
            )
        );
    }

    /**
     * Find Enrollments for a given User (Contact) in a given Course (Offer)
     *
     * Note: Filters client-side since Kajabi's Contact→Offers endpoint doesn't support offer_id filtering
     *
     * @param int|string $user_id_or_email Contact ID or email
     * @param int $course_id Offer ID
     * @param array $filters
     * @return Paginator
     */
    public function enrollmentsForUserInCourse(int|string $user_id_or_email, int $course_id, array $filters = []): Paginator
    {
        // Get all enrollments for contact (cannot filter by offer_id at API level)
        return $this->enrollmentsForUser($user_id_or_email, $filters);
    }

    /**
     * Determine if contact has existing enrollment in a course (offer)
     *
     * Note: Filters client-side by checking offer IDs since API doesn't support filtering
     *
     * @param int|string $user_id_or_email Contact ID or email
     * @param int $course_id Offer ID
     * @param array $filters
     * @return bool
     */
    public function isUserEnrolledInCourse(int|string $user_id_or_email, int $course_id, array $filters = []): bool
    {
        // Get all offers for this contact
        $enrollments = $this->enrollmentsForUser($user_id_or_email, ['max_pages' => 10]);

        // Check if any enrollment matches the course_id (offer_id)
        foreach ($enrollments->items() as $enrollment) {
            if ($enrollment->course_id == $course_id) {
                return true;
            }
        }

        return false;
    }
}