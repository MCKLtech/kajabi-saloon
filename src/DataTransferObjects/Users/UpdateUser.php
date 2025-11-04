<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Users;

use WooNinja\LMSContracts\Contracts\DTOs\Users\UpdateUserInterface;

final class UpdateUser implements UpdateUserInterface
{
    public function __construct(
        public int     $id,
        public string  $first_name,
        public string  $last_name,
        public string  $email,
        public ?string $password = null,
        public ?array  $custom_profile_fields = null,
        public ?array  $roles = null,
        public ?string $bio = null,
        public ?string $avatar_url = null,
        public ?string $company = null,
        public ?string $headline = null,
        public ?string $external_source = null,
        public ?string $affiliate_code = null,
        public ?int    $affiliate_commission = null,
        public ?string $affiliate_commission_type = null,
        public ?string $affiliate_payout_email = null,
    )
    {
    }
}
