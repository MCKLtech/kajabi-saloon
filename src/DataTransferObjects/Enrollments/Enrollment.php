<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Enrollments;

use Carbon\Carbon;
use WooNinja\KajabiSaloon\DataTransferObjects\Products\Product;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\EnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;

/**
 * One contact in one course product. id is "{productId}:{contactId}" and
 * course_id the product id, whichever offer grants it.
 *
 * Progress: $percentage_completed and $completed can't be null under
 * EnrollmentInterface; from offers and purchases they are placeholders and
 * $progress_known is false. Read real progress per product from
 * $kajabi->courseProgress (or member activity webhooks) instead.
 */
final class Enrollment implements EnrollmentInterface
{
    public function __construct(
        public int|string $id,
        public string     $user_email,
        public string     $user_name,
        public int        $user_id,
        public string     $course_name,
        public int        $course_id,
        public float      $percentage_completed,
        public bool       $expired,
        public bool       $is_free_trial,
        public bool       $completed,
        public ?Carbon    $started_at,
        public ?Carbon    $activated_at,
        public ?Carbon    $completed_at,
        public Carbon     $updated_at,
        public ?Carbon    $expiry_date,
        public ?string    $credential_id,
        public ?string    $certificate_url,
        public ?Carbon    $certificate_expiry_date,
        // Additional Kajabi-specific fields
        public ?string    $status = null,
        public ?string    $created_at = null,
        public bool       $progress_known = false,
    )
    {
    }

    /**
     * One course product of an offer the contact holds, as an Enrollment
     *
     * course_id/course_name are the product's; the offer only supplies the
     * grant. Two offers granting the same product give the same id, so callers
     * de-duplicate by id.
     *
     * @param array $offer Offer resource (JSON:API data); kept for callers, not read
     * @param array|Product $product Product resource (JSON:API data) or Product DTO
     * @param \DateTimeInterface|null $grantedAt When the offer was granted, if the caller knows (e.g. it
     *        just granted it). Kajabi's offer relationships carry no grant date, so without it
     *        started_at, activated_at and created_at are null rather than a made-up "now".
     */
    public static function fromKajabiOfferProduct(
        array $offer,
        array|Product $product,
        int $contactId,
        string $email,
        string $name,
        ?UserInterface $contact = null,
        ?\DateTimeInterface $grantedAt = null
    ): self
    {
        $granted = $grantedAt !== null ? Carbon::instance($grantedAt) : null;

        [$productId, $productTitle] = $product instanceof Product
            ? [$product->id, $product->name]
            : [(int) $product['id'], $product['attributes']['title'] ?? $product['attributes']['name'] ?? ''];

        if (empty($email) && $contact) {
            $email = $contact->email;
        }

        if (empty($name) && $contact) {
            $name = $contact->getFullName();
        }

        if (empty($contactId) && $contact) {
            $contactId = $contact->id;
        }

        return new self(
            id: self::getEnrollmentId($productId, $contactId),
            user_email: $email,
            user_name: $name,
            user_id: $contactId,
            course_name: $productTitle,
            course_id: $productId,
            percentage_completed: 0.0, // placeholder: progress_known is false
            expired: false, // a held offer has no partial or expired state
            is_free_trial: false, //Concept of free trial does not exist in Kajabi
            completed: false,
            started_at: $granted,
            activated_at: $granted?->copy(),
            completed_at: null,
            updated_at: Carbon::now(), // required by the contract; the time this was read, not a grant date
            expiry_date: null,
            credential_id: null,
            certificate_url: null,
            certificate_expiry_date: null,
            status: 'active',
            created_at: $granted?->toISOString(),
            progress_known: false,
        );
    }

    /**
     * "{productId}:{contactId}" - a string: concatenated Kajabi ids overflow an int
     */
    public static function getEnrollmentId(int $productId, int $contactId): string
    {
        return "{$productId}:{$contactId}";
    }

    /**
     * Product and contact ids from getEnrollmentId(), or null for any other string
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseEnrollmentId(int|string $id): ?array
    {
        if (!preg_match('/^(\d+):(\d+)$/', (string) $id, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Create Enrollment from Kajabi Purchase API response
     *
     * @param array $purchase The purchase data from API response
     * @param array $includedMap Optional map of included resources (e.g., offers, customers) to avoid N+1 queries
     *                          Format: ['type:id' => resource_data]
     * @throws \Exception
     */
    public static function fromKajabiPurchase(array $purchase, array $includedMap = []): self
    {
        // Extract user information from relationships and included data
        $userId = 0;
        $contactId = null;
        $userEmail = $purchase['attributes']['customer_email'] ?? '';
        $userName = $purchase['attributes']['customer_name'] ?? '';

        if (isset($purchase['relationships']['customer']['data']['id'])) {
            $customerId = $purchase['relationships']['customer']['data']['id'];
            $userId = (int)$customerId;

            // Try to get customer details from included data (if available via ?include=customer)
            $customerKey = "customers:{$customerId}";
            if (isset($includedMap[$customerKey])) {
                $customerData = $includedMap[$customerKey];
                // Extract email and name from customer data (fall back to purchase attributes)
                $userEmail = $customerData['attributes']['email'] ?? $userEmail;

                // Kajabi stores name as a single field
                $userName = $customerData['attributes']['name'] ?? $userName;

                // If name is empty, try to construct from first_name and last_name if available
                if (empty($userName)) {
                    $firstName = $customerData['attributes']['first_name'] ?? '';
                    $lastName = $customerData['attributes']['last_name'] ?? '';
                    $userName = trim("{$firstName} {$lastName}");
                }

                // Extract contact_id from customer relationships (needed for grant/revoke operations)
                if (isset($customerData['relationships']['contact']['data']['id'])) {
                    $contactId = (int)$customerData['relationships']['contact']['data']['id'];
                }
            }
        }

        $contactId = $contactId ?? $userId;

        // Extract offer_id and offer details from relationships
        // In our architecture, course_id = product_id (the product purchased)
        // while the offer is the pricing/access bundle the purchase used
        $courseId = 0;
        $courseName = $purchase['attributes']['product_name'] ?? '';
        $offerId = null;

        if (isset($purchase['relationships']['product']['data']['id'])) {
            $courseId = (int)$purchase['relationships']['product']['data']['id'];
        } elseif (isset($purchase['relationships']['offer']['data']['id'])) {
            $courseId = (int)$purchase['relationships']['offer']['data']['id'];
        }

        if (isset($purchase['relationships']['offer']['data']['id'])) {
            $offerId = $purchase['relationships']['offer']['data']['id'];

            // Try to get offer details from included data (if available via ?include=offer)
            $offerKey = "offers:{$offerId}";
            if (isset($includedMap[$offerKey])) {
                $offerData = $includedMap[$offerKey];
                // Get offer title as course name (fall back to purchase product_name)
                $courseName = $offerData['attributes']['title']
                    ?? $offerData['attributes']['internal_title']
                    ?? $courseName;
            }
        }

        if ($offerId === null) {
            throw new \Exception('Invalid purchase data: missing offer_id');
        }

        // Purchases have no status attribute live; deactivated_at is the signal. It can
        // be in the future (a scheduled end - filter[active]=true still returns those),
        // so only a past deactivated_at means the access has ended.
        $deactivatedAt = $purchase['attributes']['deactivated_at'] ?? null;
        $deactivated = $deactivatedAt !== null && Carbon::parse($deactivatedAt)->lte(Carbon::now());
        $status = $purchase['attributes']['status'] ?? ($deactivated ? 'deactivated' : 'active');
        $expired = in_array($status, ['expired', 'cancelled', 'deactivated'], true);
        $completed = $status === 'completed';
        $isFreeTrial = (int)($purchase['attributes']['amount_in_cents'] ?? $purchase['attributes']['amount'] ?? 0) === 0;
        $expiresAt = $purchase['attributes']['expires_at'] ?? null;

        return new self(
            id: self::getEnrollmentId($courseId, $contactId),
            user_email: $userEmail,
            user_name: $userName,
            user_id: $contactId,
            course_name: $courseName,
            course_id: $courseId, // This is the product_id
            percentage_completed: 0.0, // placeholder: purchases carry no progress (progress_known is false)
            expired: $expired,
            is_free_trial: $isFreeTrial,
            completed: $completed,
            started_at: isset($purchase['attributes']['effective_start_at'])
                ? Carbon::parse($purchase['attributes']['effective_start_at'])
                : (isset($purchase['attributes']['created_at']) ? Carbon::parse($purchase['attributes']['created_at']) : null),
            activated_at: isset($purchase['attributes']['activated_at'])
                ? Carbon::parse($purchase['attributes']['activated_at'])
                : (isset($purchase['attributes']['created_at']) ? Carbon::parse($purchase['attributes']['created_at']) : null),
            completed_at: null, // Kajabi doesn't track completion
            updated_at: isset($purchase['attributes']['updated_at'])
                ? Carbon::parse($purchase['attributes']['updated_at'])
                : Carbon::now(),
            expiry_date: $expiresAt
                ? Carbon::parse($expiresAt)
                : ($deactivatedAt ? Carbon::parse($deactivatedAt) : null),
            credential_id: null, // Kajabi handles credentials differently
            certificate_url: null,
            certificate_expiry_date: null,
            // Kajabi-specific fields
            status: $status,
            created_at: $purchase['attributes']['created_at'] ?? null,
            progress_known: false,
        );
    }

}
