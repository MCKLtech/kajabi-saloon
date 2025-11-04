<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Products;

use Carbon\Carbon;
use WooNinja\LMSContracts\Contracts\DTOs\Products\ProductInterface;

class Product implements ProductInterface
{
    public function __construct(
        public int     $id,
        public Carbon  $created_at,
        public int     $productable_id,
        public string  $productable_type,
        public float   $price,
        public int     $position,
        public string  $status,
        public string  $name,
        public bool    $private,
        public bool    $hidden,
        public bool    $subscription,
        public ?int    $days_until_expiry,
        public bool    $has_certificate,
        public ?string $keywords,
        public ?string $seo_title,
        public ?string $seo_description,
        public array   $collection_ids,
        public array   $related_product_ids,
        public ?string $description,
        public ?string $card_image_url,
        public string  $slug,
        public array   $product_prices,
        // Additional Kajabi-specific fields
        public ?string $url = null,
        public ?string $updated_at = null,
    )
    {
    }

    /**
     * Create Product from Kajabi Product API response
     */
    public static function fromResponse(array $product): self
    {
        return self::fromKajabiProduct($product);
    }

    /**
     * Create Product from Kajabi Product API response
     */
    public static function fromKajabiProduct(array $product): self
    {
        // Extract pricing information from offers relationship
        $price = 0.0;
        $isSubscription = false;
        $productPrices = [];
        
        if (isset($product['relationships']['offers']['data'])) {
            $offers = $product['relationships']['offers']['data'];
            if (is_array($offers)) {
                foreach ($offers as $offer) {
                    // We'll need to look at included data for full offer details
                    // For now, we can extract basic info if available in attributes
                    if (isset($offer['attributes'])) {
                        $offerPrice = (float) ($offer['attributes']['price_in_cents'] ?? 0) / 100;
                        if ($price == 0.0) {
                            $price = $offerPrice; // Use first offer's price as main price
                        }
                        
                        $productPrices[] = [
                            'id' => $offer['id'],
                            'price' => $offerPrice,
                            'currency' => $offer['attributes']['currency'] ?? 'USD',
                            'subscription' => (bool) ($offer['attributes']['subscription'] ?? false)
                        ];
                        
                        if ($offer['attributes']['subscription'] ?? false) {
                            $isSubscription = true;
                        }
                    }
                }
            }
        }
        
        // Extract related product IDs from relationships
        $relatedProductIds = [];
        if (isset($product['relationships']['related_products']['data'])) {
            $relatedProducts = $product['relationships']['related_products']['data'];
            if (is_array($relatedProducts)) {
                $relatedProductIds = array_map(fn($related) => (int) $related['id'], $relatedProducts);
            }
        }
        
        return new self(
            id: (int) $product['id'],
            created_at: isset($product['attributes']['created_at']) 
                ? Carbon::parse($product['attributes']['created_at']) 
                : Carbon::now(),
            productable_id: (int) $product['id'], // In Kajabi, product is the main entity
            productable_type: 'course', // Kajabi doesn't distinguish like Thinkific, assume everythign is a course
            price: $price,
            position: (int) ($product['attributes']['position'] ?? 0),
            status: $product['attributes']['status'] ?? 'published',
            name: $product['attributes']['name'] ?? $product['attributes']['title'] ?? '',
            private: (bool) ($product['attributes']['private'] ?? false),
            hidden: (bool) ($product['attributes']['hidden'] ?? false),
            subscription: $isSubscription,
            days_until_expiry: isset($product['attributes']['days_until_expiry']) ? (int) $product['attributes']['days_until_expiry'] : null,
            has_certificate: (bool) ($product['attributes']['has_certificate'] ?? false),
            keywords: $product['attributes']['keywords'] ?? null,
            seo_title: $product['attributes']['seo_title'] ?? null,
            seo_description: $product['attributes']['seo_description'] ?? null,
            collection_ids: [], // Kajabi doesn't use collections the same way
            related_product_ids: $relatedProductIds,
            description: $product['attributes']['description'] ?? null,
            card_image_url: $product['attributes']['image_url'] ?? null,
            slug: $product['attributes']['slug'] ?? '',
            product_prices: $productPrices,
            // Kajabi-specific fields
            url: $product['attributes']['url'] ?? null,
            updated_at: $product['attributes']['updated_at'] ?? null,
        );
    }

}
