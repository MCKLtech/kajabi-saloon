<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Sites;

final class Site
{
    public function __construct(
        public int     $id,
        public string  $name,
        public string  $domain,
        public ?string $subdomain,
        public ?string $description,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    )
    {
    }

    public static function fromKajabiSite(array $site): self
    {
        return new self(
            id: (int) $site['id'],
            name: $site['attributes']['name'] ?? '',
            domain: $site['attributes']['domain'] ?? '',
            subdomain: $site['attributes']['subdomain'] ?? null,
            description: $site['attributes']['description'] ?? null,
            created_at: $site['attributes']['created_at'] ?? null,
            updated_at: $site['attributes']['updated_at'] ?? null,
        );
    }
}
