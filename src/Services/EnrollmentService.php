<?php

namespace WooNinja\KajabiSaloon\Services;

use Carbon\Carbon;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;
use Saloon\Exceptions\Request\RequestException;
use WooNinja\KajabiSaloon\Requests\Offers\GetOffer;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use Saloon\Exceptions\Request\FatalRequestException;
use WooNinja\KajabiSaloon\Requests\Offers\GrantOffer;
use WooNinja\KajabiSaloon\Requests\Purchases\GetPurchases;
use WooNinja\KajabiSaloon\Requests\Customers\GetCustomers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactOffers;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContactsWithProduct;
use WooNinja\KajabiSaloon\Requests\Products\GetProduct;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\Enrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\CreateEnrollment;
use WooNinja\KajabiSaloon\DataTransferObjects\Enrollments\DeleteEnrollment;
use WooNinja\LMSContracts\Contracts\Services\EnrollmentServiceInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\EnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\ReadEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\UpdateEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\CreateEnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\DeleteEnrollmentInterface;

/**
 * Enrollment Service for Kajabi
 *
 * An enrollment is one contact in one course PRODUCT. A contact holding an
 * offer is enrolled in each course product the offer grants (communities,
 * coaching, podcasts and access groups don't count; cohort courses do).
 *
 * - user_id = Contact ID
 * - Enrollment::$course_id = Product ID, Enrollment::$id = "{productId}:{contactId}"
 * - Reads take a PRODUCT id: get(), enrollmentsForCourse(), enrollmentsForUserInCourse(),
 *   isUserEnrolledInCourse()
 * - Writes take an OFFER id, since access is granted by offer: create(), createMany(),
 *   update(), expire(), enroll(), unenroll()
 *
 * Grant: POST /v1/contacts/{contact_id}/relationships/offers
 * Revoke: DELETE /v1/contacts/{contact_id}/relationships/offers
 *
 * Offer -> course products comes from ProductService::courseProductsForOffer(),
 * cached (KajabiService::setCache()).
 */
class EnrollmentService extends Resource implements EnrollmentServiceInterface
{
    /**
     * Get an Enrollment by ID
     *
     * Reads the product and contact from a "{productId}:{contactId}" enrollment_id,
     * else from user_id (Contact ID) and course_id (Product ID).
     *
     * @param ReadEnrollmentInterface $enrollment_id
     * @return EnrollmentInterface
     * @throws \InvalidArgumentException if the contact isn't enrolled in the product
     */
    public function get(ReadEnrollmentInterface $enrollment_id): EnrollmentInterface
    {
        [$productId, $contactId] = Enrollment::parseEnrollmentId($enrollment_id->enrollment_id)
            ?? [$enrollment_id->course_id, $enrollment_id->user_id];

        if ($productId === null || $contactId === null) {
            throw new \InvalidArgumentException(
                'ReadEnrollmentInterface needs a "{productId}:{contactId}" enrollment_id, or user_id and course_id'
            );
        }

        foreach ($this->enrollmentsForUserInCourse($contactId, $productId)->items() as $enrollment) {
            return $enrollment;
        }

        throw new \InvalidArgumentException("Contact {$contactId} is not enrolled in product {$productId}");
    }

    /**
     * List enrollments (Purchases)
     *
     * @param array $filters
     * @return Paginator
     */
    public function enrollments(array $filters = []): Paginator
    {
        // Resolve Thinkific-style email filters to a Kajabi customer_id filter.
        // GET /v1/purchases has no customer_email filter; the supported path is
        // to find the customer by email, then filter purchases by customer_id.
        if (isset($filters['query[email]']) || isset($filters['email'])) {
            $email = $filters['query[email]'] ?? $filters['email'];
            unset($filters['query[email]'], $filters['email']);

            $customerId = $this->resolveCustomerIdByEmail($email);

            if ($customerId === null) {
                throw new \InvalidArgumentException("No Kajabi customer found for email: {$email}");
            }

            $filters['user_id'] = $customerId;
        }

        return $this->connector
            ->paginate(new GetPurchases($filters, $this->getDefaultSiteId()));
    }

    /**
     * Resolve a customer ID from an email address using GET /v1/customers?filter[search]=
     *
     * @param string $email
     * @return int|null
     */
    private function resolveCustomerIdByEmail(string $email): ?int
    {
        // filter[search] matches partially, so take the exact (case-insensitive) address only
        $customers = $this->connector
            ->send(new GetCustomers(['search' => $email, 'limit' => UserService::EMAIL_SEARCH_PAGE_SIZE], $this->getDefaultSiteId()))
            ->dtoOrFail();

        foreach ($customers as $customer) {
            if (strcasecmp($customer->email, $email) === 0) {
                return $customer->id;
            }
        }

        return null;
    }

    /**
     * Create an Enrollment (grants an OFFER to a CONTACT)
     *
     * Returns the enrollment in the offer's first course product; createMany()
     * returns all of them.
     *
     * @param CreateEnrollmentInterface $enrollment user_id=Contact ID, course_id=Offer ID
     * @return EnrollmentInterface
     * @throws \UnexpectedValueException when the offer (granted anyway) grants no course products
     * @throws FatalRequestException
     * @throws RequestException|\JsonException
     */
    public function create(CreateEnrollmentInterface $enrollment): EnrollmentInterface
    {
        $enrollments = $this->createMany($enrollment);

        if ($enrollments === []) {
            throw new \UnexpectedValueException(
                "Offer {$enrollment->course_id} was granted to contact {$enrollment->user_id}, but it grants no course products, so there is no enrollment to return. Use createMany()."
            );
        }

        return $enrollments[0];
    }

    /**
     * Grant an OFFER to a CONTACT and return one Enrollment per course product it grants
     *
     * @param CreateEnrollmentInterface $enrollment user_id=Contact ID, course_id=Offer ID
     * @return Enrollment[] in the offer's product order; empty when it grants no courses
     * @throws FatalRequestException
     * @throws RequestException|\JsonException
     */
    public function createMany(CreateEnrollmentInterface $enrollment): array
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

        // The one place the grant time is known. (Re-granting an offer the contact
        // already has also lands here, so this is the latest grant, not the first.)
        $grantedAt = Carbon::now();

        // Fresh offer, so the cached offer -> products entry is current too
        $offer = $this->connector->send(new GetOffer($offerId))->json('data');
        $products = (new ProductService($this->kajabi))->courseProductsForOffer($offerId, $offer);

        return array_values(array_map(
            fn(array $product) => Enrollment::fromKajabiOfferProduct(
                $offer,
                $product,
                $contact->id,
                $contact->email,
                $contact->first_name . ' ' . $contact->last_name,
                grantedAt: $grantedAt
            ),
            $products
        ));
    }

    /**
     * Enroll a user in a course (grants the offer to the contact)
     *
     * Thinkific-compatible convenience wrapper around create().
     * - user_id_or_email: Contact ID (int) or Contact email (string)
     * - course_id: Offer ID (course_id maps to offer_id in Kajabi)
     * - offer_id: Optional explicit Offer ID override (defaults to course_id)
     *
     * @param int|string $user_id_or_email
     * @param int $course_id
     * @param int|null $offer_id
     * @return EnrollmentInterface
     * @throws FatalRequestException
     * @throws RequestException|\JsonException
     */
    public function enroll(int|string $user_id_or_email, int $course_id, ?int $offer_id = null): EnrollmentInterface
    {
        $contactId = $this->resolveContactId($user_id_or_email);
        $offerId = $offer_id ?? $course_id;

        return $this->create(new CreateEnrollment(
            user_id: $contactId,
            course_id: $offerId
        ));
    }

    /**
     * Unenroll a user from a course (revokes the offer from the contact)
     *
     * Thinkific-compatible convenience wrapper around expire().
     *
     * @param int|string $user_id_or_email
     * @param int $course_id
     * @return Response
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function unenroll(int|string $user_id_or_email, int $course_id): Response
    {
        $contactId = $this->resolveContactId($user_id_or_email);

        return $this->expire(new DeleteEnrollment(
            enrollment_id: 0,
            user_id: $contactId,
            course_id: $course_id
        ));
    }

    /**
     * Resolve a Contact ID from a numeric ID or an email address
     *
     * @param int|string $user_id_or_email
     * @return int
     */
    private function resolveContactId(int|string $user_id_or_email): int
    {
        if (is_numeric($user_id_or_email)) {
            return (int)$user_id_or_email;
        }

        $userService = new UserService($this->kajabi);
        $contact = $userService->findByEmail((string)$user_id_or_email);

        if ($contact === null) {
            throw new \InvalidArgumentException("Contact not found for email: {$user_id_or_email}");
        }

        return $contact->id;
    }

    /**
     * Update an Enrollment (course_id = Offer ID)
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
            // Revoke the offer from the contact (a no-op if it isn't held)
            return $this->revokeOffer($contactId, $offerId);
        } else {
            // Grant the offer to the contact
            return $this->connector->send(
                new GrantOffer((string)$contactId, (string)$offerId, $this->getDefaultSiteId(), false)
            );
        }
    }

    /**
     * Expire an Enrollment (revokes the OFFER from CONTACT; course_id = Offer ID)
     *
     * Ends every course enrollment that offer granted, except courses another
     * held offer also grants. Idempotent: an offer that isn't held returns the
     * contact lookup's response instead of Kajabi's 400; other errors throw.
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

        return $this->revokeOffer($contactId, $offerId);
    }

    /**
     * Enrollments in a course Product: one per contact with access to it, by any offer
     *
     * API: GET /v1/contacts?filter[has_product_id]={course_id}
     *
     * @param int $course_id Product ID
     * @param array $filters
     * @return Paginator
     */
    public function enrollmentsForCourse(int $course_id, array $filters = []): Paginator
    {
        // The title, from the cached catalog when it's a course product
        $product = (new ProductService($this->kajabi))->courseProductResources()[$course_id]
            ?? $this->connector->send(new GetProduct($course_id))->json('data');

        return $this->connector->paginate(
            new GetContactsWithProduct($product, $filters, $this->getDefaultSiteId())
        );
    }

    /**
     * Enrollments of a User (Contact): one per course product across the offers they hold,
     * de-duplicated by product
     *
     * Uses GET /v1/contacts/{contact_id}?include=offers (one request) plus the cached
     * offer -> course products lookup. A single page.
     *
     * @param int|string $user_id_or_email Contact ID or email
     * @param array $filters
     * @return Paginator
     */
    public function enrollmentsForUser(int|string $user_id_or_email, array $filters = []): Paginator
    {
        return $this->contactEnrollments($user_id_or_email, $filters);
    }

    /**
     * The User's (Contact's) enrollment in a course Product, if any offer they hold grants it
     *
     * @param int|string $user_id_or_email Contact ID or email
     * @param int $course_id Product ID
     * @param array $filters
     * @return Paginator
     */
    public function enrollmentsForUserInCourse(int|string $user_id_or_email, int $course_id, array $filters = []): Paginator
    {
        return $this->contactEnrollments($user_id_or_email, $filters, $course_id);
    }

    /**
     * Whether any offer the contact holds grants this course Product
     *
     * @param int|string $user_id_or_email Contact ID or email
     * @param int $course_id Product ID
     * @param array $filters
     * @return bool
     */
    public function isUserEnrolledInCourse(int|string $user_id_or_email, int $course_id, array $filters = []): bool
    {
        foreach ($this->enrollmentsForUserInCourse($user_id_or_email, $course_id, $filters)->items() as $enrollment) {
            return true;
        }

        return false;
    }

    private function contactEnrollments(int|string $user_id_or_email, array $filters, ?int $productId = null): Paginator
    {
        $contact = $this->resolveContact($user_id_or_email);

        if ($contact === null) {
            return $this->noEnrollments();
        }

        $products = new ProductService($this->kajabi);

        return $this->connector->paginate(
            new GetContactOffers(
                $contact,
                fn(int $offerId) => $products->courseProductsForOffer($offerId),
                $filters,
                $this->getDefaultSiteId(),
                $productId
            )
        );
    }

    /**
     * Course progress rows for a Contact in an Offer, one per course product in the offer
     *
     * Resolves Contact -> Customer and Offer -> Products, then reads
     * GET /v1/course_progress per product. Products the endpoint doesn't
     * support (non-course products, cohorts) return 404 and are skipped.
     * Costs 2 + one request per product in the offer.
     *
     * @param int|string $user_id_or_email Contact ID or email
     * @param int $course_id Offer ID
     * @return CourseProgress[]
     * @see CourseProgressService::forCustomer() for one product
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function progress(int|string $user_id_or_email, int $course_id): array
    {
        $contact = $this->resolveContact($user_id_or_email);

        // No customer record yet means the contact has never been granted anything
        if (!$contact instanceof User || $contact->customer_id === null) {
            return [];
        }

        $offer = $this->connector->send(new GetOffer($course_id))->dtoOrFail();

        $courseProgress = new CourseProgressService($this->kajabi);
        $rows = [];

        foreach ($offer->product_ids as $productId) {
            try {
                $row = $courseProgress->forCustomer($productId, $contact->customer_id);
            } catch (RequestException $e) {
                if ($e->getResponse()->status() === 404) {
                    continue;
                }

                throw $e;
            }

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * A paginator with no items, for an unknown contact: no customer has id 0 (verified live)
     */
    private function noEnrollments(): Paginator
    {
        return $this->enrollments(['user_id' => 0, 'limit' => 1]);
    }
}
