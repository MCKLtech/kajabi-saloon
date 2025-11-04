<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Users;

use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;

final class User implements UserInterface
{
    public function __construct(
        public int     $id,
        public string  $first_name,
        public string  $last_name,
        public string  $email,
        public ?string $password,
        public array   $roles,
        public ?string $avatar_url,
        public ?string $bio,
        public ?string $company,
        public ?string $headline,
        public ?string $external_source,
        public ?string $affiliate_code,
        public ?int    $affiliate_commission,
        public ?string $affiliate_commission_type,
        public ?string $affiliate_payout_email,
        public ?array  $custom_profile_fields,
        // Additional Kajabi-specific fields
        public ?string $phone_number = null,
        public ?bool   $subscribed = null,
        public ?string $address_line_1 = null,
        public ?string $address_line_2 = null,
        public ?string $address_city = null,
        public ?string $address_state = null,
        public ?string $address_country = null,
        public ?string $address_zip = null,
        public ?string $business_number = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    )
    {
    }

    public function getFullName(): string
    {
        $fullName = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return !empty($fullName) ? $fullName : $this->email;
    }

    /**
     * Create User from Kajabi Contact API response
     */
    public static function fromKajabiContact(array $contact): self
    {
        // Extract name parts from Kajabi's single "name" field
        $nameParts = explode(' ', $contact['attributes']['name'] ?? '', 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        // Extract roles from relationships or determine from context
        $roles = [];
        
        // Kajabi doesn't have traditional roles like Thinkific, but we can infer some info
        // Default role is 'student' unless we can determine otherwise
        $roles[] = 'student';
        
        // If there are admin relationships or special attributes, we could add 'admin'
        if (isset($contact['relationships']['admin']) || 
            isset($contact['attributes']['is_admin']) && $contact['attributes']['is_admin']) {
            $roles[] = 'admin';
        }
        
        return new self(
            id: (int) $contact['id'],
            first_name: $firstName,
            last_name: $lastName,
            email: $contact['attributes']['email'] ?? '',
            password: null, // Kajabi doesn't expose passwords
            roles: $roles,
            avatar_url: null, // Kajabi contacts don't have avatar_url
            bio: null,
            company: null,
            headline: null,
            external_source: null,
            affiliate_code: null,
            affiliate_commission: null,
            affiliate_commission_type: null,
            affiliate_payout_email: null,
            custom_profile_fields: [
                'custom_1' => $contact['attributes']['custom_1'] ?? null,
                'custom_2' => $contact['attributes']['custom_2'] ?? null,
                'custom_3' => $contact['attributes']['custom_3'] ?? null,
            ],
            // Kajabi-specific fields
            phone_number: $contact['attributes']['phone_number'] ?? null,
            subscribed: $contact['attributes']['subscribed'] ?? null,
            address_line_1: $contact['attributes']['address_line_1'] ?? null,
            address_line_2: $contact['attributes']['address_line_2'] ?? null,
            address_city: $contact['attributes']['address_city'] ?? null,
            address_state: $contact['attributes']['address_state'] ?? null,
            address_country: $contact['attributes']['address_country'] ?? null,
            address_zip: $contact['attributes']['address_zip'] ?? null,
            business_number: $contact['attributes']['business_number'] ?? null,
            created_at: $contact['attributes']['created_at'] ?? null,
            updated_at: $contact['attributes']['updated_at'] ?? null,
        );
    }
}
