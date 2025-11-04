<?php

namespace WooNinja\KajabiSaloon\Requests\Contacts;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UpdateUserInterface;

class UpdateContact extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PATCH;

    public function __construct(
        protected int $contactId,
        protected UpdateUserInterface $user
    ) {
    }

    public function resolveEndpoint(): string
    {
        return "/contacts/{$this->contactId}";
    }

    protected function defaultBody(): array
    {
        // Build the full name from first_name and last_name
        $name = trim($this->user->first_name . ' ' . $this->user->last_name);

        // Build attributes array - Kajabi supports these fields
        $attributes = [
            'name' => $name,
            'email' => $this->user->email,
        ];

        // Note: Password updates are not supported via PATCH in Kajabi
        // Password can only be set during user creation or via password reset flow

        // Map external_source to external_user_id if available
        // external_user_id is only updatable if contact has been granted an offer or made a purchase
        if (isset($this->user->external_source) && $this->user->external_source !== null) {
            $attributes['external_user_id'] = $this->user->external_source;
        }

        // Map custom profile fields to Kajabi custom fields (custom_1, custom_2, custom_3)
        // Kajabi supports up to 3 custom fields per site
        if (isset($this->user->custom_profile_fields) && is_array($this->user->custom_profile_fields)) {
            $customFieldIndex = 1;
            foreach ($this->user->custom_profile_fields as $field) {
                if ($customFieldIndex <= 3 && isset($field['value'])) {
                    $attributes["custom_{$customFieldIndex}"] = $field['value'];
                    $customFieldIndex++;
                }
            }
        }

        // Note: Kajabi does not support these Thinkific fields:
        // - roles (managed differently in Kajabi)
        // - bio (not available in Kajabi Contact API)
        // - avatar_url (not updatable via API)
        // - company (not available in Kajabi Contact API)
        // - headline (not available in Kajabi Contact API)
        // - affiliate_code, affiliate_commission, affiliate_commission_type, affiliate_payout_email
        //   (affiliate features are managed differently in Kajabi)

        // Build the JSON:API formatted request body
        $body = [
            'data' => [
                'id' => (string) $this->contactId,
                'type' => 'contacts',
                'attributes' => $attributes,
            ],
        ];

        return $body;
    }
}
