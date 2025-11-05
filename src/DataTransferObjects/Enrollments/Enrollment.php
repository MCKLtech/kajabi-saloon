<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Enrollments;

use Carbon\Carbon;
use WooNinja\KajabiSaloon\Services\UserService;
use WooNinja\LMSContracts\Contracts\DTOs\Enrollments\EnrollmentInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;

final class Enrollment implements EnrollmentInterface
{
    public function __construct(
        public int     $id,
        public string  $user_email,
        public string  $user_name,
        public int     $user_id,
        public string  $course_name,
        public int     $course_id,
        public float   $percentage_completed,
        public bool    $expired,
        public bool    $is_free_trial,
        public bool    $completed,
        public ?Carbon $started_at,
        public ?Carbon $activated_at,
        public ?Carbon $completed_at,
        public Carbon  $updated_at,
        public ?Carbon $expiry_date,
        public ?string $credential_id,
        public ?string $certificate_url,
        public ?Carbon $certificate_expiry_date,
        // Additional Kajabi-specific fields
        public ?string $status = null,
        public ?string $created_at = null,
    )
    {
    }

    /**
     * Create Enrollment from Kajabi Offer (when offer is granted to a customer)
     *
     * @param array $offer Offer data from API response
     * @param int $customerId Customer ID who received the offer
     * @param string $customerEmail Customer email
     * @param string $customerName Customer name
     * @param UserInterface|null $contact
     * @return self
     */
    public static function fromKajabiOffer(array $offer, int $customerId, string $customerEmail, string $customerName, ?UserInterface $contact = null): self
    {
        $offerId = (int)$offer['id'];
        $offerTitle = $offer['attributes']['title'] ?? $offer['attributes']['internal_title'] ?? '';

        if(empty($customerEmail) && $contact) {
            $customerEmail = $contact->email;
        }

        if(empty($customerName) && $contact) {
            $customerName = $contact->getFullName();
        }

        if(empty($customerId) && $contact) {
            $customerId = $contact->id;
        }

        return new self(
            id: self::getEnrollmentId($offerId, $customerId),
            user_email: $customerEmail,
            user_name: $customerName,
            user_id: $customerId, // This is customer_id
            course_name: $offerTitle,
            course_id: $offerId, // course_id is offer_id
            percentage_completed: 0.0,
            expired: false,
            is_free_trial: ($offer['attributes']['price_in_cents'] ?? 0) == 0,
            completed: false,
            started_at: Carbon::now(), // Granted now
            activated_at: Carbon::now(),
            completed_at: null,
            updated_at: Carbon::now(),
            expiry_date: null,
            credential_id: null,
            certificate_url: null,
            certificate_expiry_date: null,
            status: 'active',
            created_at: Carbon::now()->toISOString(),
        );
    }

    public static function getEnrollmentId($offerId, $contactId): int
    {
        return (int)"{$offerId}{$contactId}";
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
        $userEmail = '';
        $userName = '';

        if (isset($purchase['relationships']['customer']['data']['id'])) {
            $customerId = $purchase['relationships']['customer']['data']['id'];
            $userId = (int)$customerId;

            // Try to get customer details from included data (if available via ?include=customer)
            $customerKey = "customers:{$customerId}";
            if (isset($includedMap[$customerKey])) {
                $customerData = $includedMap[$customerKey];
                // Extract email and name from customer data
                $userEmail = $customerData['attributes']['email'] ?? '';

                // Kajabi stores name as a single field
                $userName = $customerData['attributes']['name'] ?? '';

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
        // In our architecture, course_id = offer_id
        $courseId = 0;
        $courseName = '';
        $offerId = null;

        if (isset($purchase['relationships']['offer']['data']['id'])) {
            $offerId = $purchase['relationships']['offer']['data']['id'];
            $courseId = (int)$offerId;

            // Try to get offer details from included data (if available via ?include=offer)
            $offerKey = "offers:{$offerId}";
            if (isset($includedMap[$offerKey])) {
                $offerData = $includedMap[$offerKey];
                // Get offer title as course name
                $courseName = $offerData['attributes']['title']
                    ?? $offerData['attributes']['internal_title']
                    ?? '';
            }
        }

        // Determine enrollment status
        $deactivatedAt = $purchase['attributes']['deactivated_at'] ?? null;
        $status = $deactivatedAt ? 'deactivated' : 'active';

        if ($offerId === null) {
            throw new \Exception('Invalid purchase data: missing offer_id');
        }

        return new self(
            id: (int)self::getEnrollmentId($offerId, $contactId),
            user_email: $userEmail,
            user_name: $userName,
            user_id: $contactId,
            course_name: $courseName,
            course_id: $courseId, // This is the offer_id
            percentage_completed: 0.0, // Kajabi doesn't track completion percentage the same way
            expired: $status === 'deactivated',
            is_free_trial: ($purchase['attributes']['amount_in_cents'] ?? 0) == 0,
            completed: false, // Kajabi purchases don't have a completed state
            started_at: isset($purchase['attributes']['effective_start_at'])
                ? Carbon::parse($purchase['attributes']['effective_start_at'])
                : (isset($purchase['attributes']['created_at']) ? Carbon::parse($purchase['attributes']['created_at']) : null),
            activated_at: isset($purchase['attributes']['effective_start_at'])
                ? Carbon::parse($purchase['attributes']['effective_start_at'])
                : (isset($purchase['attributes']['created_at']) ? Carbon::parse($purchase['attributes']['created_at']) : null),
            completed_at: null, // Kajabi doesn't track completion
            updated_at: isset($purchase['attributes']['updated_at'])
                ? Carbon::parse($purchase['attributes']['updated_at'])
                : Carbon::now(),
            expiry_date: $deactivatedAt
                ? Carbon::parse($deactivatedAt)
                : null,
            credential_id: null, // Kajabi handles credentials differently
            certificate_url: null,
            certificate_expiry_date: null,
            // Kajabi-specific fields
            status: $status,
            created_at: $purchase['attributes']['created_at'] ?? null,
        );
    }

}
