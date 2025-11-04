<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Users;

use WooNinja\LMSContracts\Contracts\DTOs\Users\CreateUserInterface;

final class CreateUser implements CreateUserInterface
{
    public function __construct(
        public string  $first_name,
        public string  $last_name,
        public string  $email,
        public ?string $password = null,
        public bool    $skip_custom_fields_validation = false,
        public bool    $send_welcome_email = false,
        public ?array  $custom_profile_fields = null,
        public ?array  $roles = null,
        public ?string $bio = null,
        public ?string $company = null,
        public ?string $headline = null,
        public ?string $affiliate_code = null,
        public ?int    $affiliate_commission = null,
        public ?string $affiliate_commission_type = null,
        public ?string $affiliate_payout_email = null,
        public ?string $external_id = null,
        public ?string $provider = null,
    )
    {
    }
}
