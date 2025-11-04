<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Offers;

use Carbon\Carbon;
use WooNinja\LMSContracts\Contracts\DTOs\Products\ProductInterface;

/**
 * Offer DTO that implements ProductInterface for Thinkific compatibility
 *
 * CRITICAL ARCHITECTURE:
 * - In the B2B Dashboard, users see "Products" or "Courses" they can enroll in
 * - In Kajabi, users are enrolled by granting them OFFERS
 * - This DTO masquerades as a "Product" so the dashboard works seamlessly
 * - When dashboard thinks it's enrolling user in "Product 789", it's actually "Offer 789"
 *
 * FIELD MAPPING (Offer → Product):
 * - id (offer_id) → id (product_id in dashboard)
 * - title → name
 * - price_in_cents / 100 → price
 * - product_ids[0] → productable_id (first product this offer grants access to)
 * - 'Offer' → productable_type
 */
final class Offer implements ProductInterface
{
    public function __construct(
        // Required by ProductInterface - standard Thinkific Product fields
        public int     $id,                    // Offer ID (dashboard thinks it's product_id)
        public Carbon  $created_at,
        public int     $productable_id,        // First product_id from products relationship
        public string  $productable_type,      // Always 'Course' to maintain compatibility with Thinkific
        public float   $price,                 // price_in_cents / 100
        public int     $position,              // Position in list
        public string  $status,                // 'published' or 'draft'
        public string  $name,                  // Offer title
        public bool    $private,               // Not publicly listed
        public bool    $hidden,                // Hidden from catalog
        public bool    $subscription,          // Is this a subscription offer?
        public ?int    $days_until_expiry,     // Expiry in days
        public bool    $has_certificate,       // Products in offer have certificates
        public ?string $keywords,
        public ?string $seo_title,
        public ?string $seo_description,
        public array   $collection_ids,        // Not used in Kajabi
        public array   $related_product_ids,   // Not used in Kajabi
        public ?string $description,           // Offer description
        public ?string $card_image_url,        // Image for display
        public string  $slug,                  // URL slug
        public array   $product_prices,        // Array of pricing options

        // Kajabi-specific fields (not in ProductInterface but useful)
        public string  $internal_title = '',   // Internal reference name
        public int     $price_in_cents = 0,    // Original price in cents
        public string  $payment_type = '',     // 'one_time', 'subscription', etc.
        public string  $token = '',            // Offer token/code
        public string  $checkout_url = '',     // Direct checkout URL
        public bool    $recurring_offer = false,
        public bool    $one_time = false,
        public bool    $single = false,
        public bool    $free = false,
        public array   $product_ids = [],      // All products this offer grants access to
        public ?string $updated_at = null,
    )
    {
    }

    /**
     * Create Offer DTO from Kajabi Offer API response
     *
     * Transforms Kajabi offer into ProductInterface-compatible structure
     */
    public static function fromKajabiOffer(array $offer): self
    {

        // Extract product IDs from relationships
        $productIds = [];
        $firstProductId = 0;
        if (isset($offer['relationships']['products']['data']) && is_array($offer['relationships']['products']['data'])) {
            $productIds = array_map(fn($product) => (int)$product['id'], $offer['relationships']['products']['data']);
            $firstProductId = $productIds[0] ?? 0; // Use first product as productable_id
        }

        // Calculate price from cents
        $priceInCents = (int)($offer['attributes']['price_in_cents'] ?? 0);
        $price = $priceInCents / 100;

        // Determine if subscription
        $isSubscription = (bool)($offer['attributes']['subscription'] ?? false);
        $isRecurring = (bool)($offer['attributes']['recurring_offer'] ?? false);

        // Build product_prices array for compatibility
        $productPrices = [
            [
                'id' => (int)$offer['id'],
                'price' => $price,
                'currency' => $offer['attributes']['currency'] ?? 'USD',
                'subscription' => $isSubscription,
                'payment_type' => $offer['attributes']['payment_type'] ?? 'one_time',
            ]
        ];

        // Use title or internal_title for name
        $name = $offer['attributes']['title'] ?? $offer['attributes']['internal_title'] ?? 'Untitled Offer';
        $description = $offer['attributes']['description'] ?? null;

        if ($description) {
            $description = trim(
                preg_replace('/\s+/', ' ', strip_tags($description))
            );
        }

        return new self(
        // ProductInterface required fields
            id: (int)$offer['id'],
            created_at: isset($offer['attributes']['created_at'])
                ? Carbon::parse($offer['attributes']['created_at'])
                : Carbon::now(),
            productable_id: (int)$offer['id'],
            productable_type: 'course', //Assume everything is a course
            price: $price,
            position: 0, // Offers don't have explicit position
            status: 'published', // Offers are published if they exist
            name: $name,
            private: false, // Kajabi handles this differently
            hidden: false,
            subscription: $isSubscription,
            days_until_expiry: null, // Would need to check products
            has_certificate: false,
            keywords: null,
            seo_title: $name,
            seo_description: strip_tags($description),
            collection_ids: [],
            related_product_ids: [],
            description: strip_tags($description),
            card_image_url: null,
            slug: $offer['attributes']['token'] ?? '',

            // Kajabi-specific fields
            product_prices: $productPrices,
            internal_title: $offer['attributes']['internal_title'] ?? '',
            price_in_cents: $priceInCents,
            payment_type: $offer['attributes']['payment_type'] ?? '',
            token: $offer['attributes']['token'] ?? '',
            checkout_url: $offer['attributes']['checkout_url'] ?? '',
            recurring_offer: $isRecurring,
            one_time: (bool)($offer['attributes']['one_time'] ?? false),
            single: (bool)($offer['attributes']['single'] ?? false),
            free: (bool)($offer['attributes']['free'] ?? false),
            product_ids: $productIds,
            updated_at: $offer['attributes']['updated_at'] ?? null,
        );
    }
}
