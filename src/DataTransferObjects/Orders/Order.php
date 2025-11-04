<?php

namespace WooNinja\KajabiSaloon\DataTransferObjects\Orders;

use Carbon\Carbon;
use WooNinja\LMSContracts\Contracts\DTOs\Orders\OrderInterface;

class Order implements OrderInterface
{
    public function __construct(
        public int     $id,
        public Carbon  $created_at,
        public int     $user_id,
        public string  $user_email,
        public string  $user_name,
        public string  $product_name,
        public int     $product_id,
        public float   $amount_dollars,
        public int     $amount_cents,
        public bool    $subscription,
        public ?string $coupon_code,
        public ?int    $coupon_id,
        public ?string $affiliate_referral_code,
        public string  $status,
        public array   $items,
        // Additional Kajabi-specific fields
        public ?string $updated_at = null,
        public ?string $currency = null,
    )
    {
    }

    /**
     * Create Order from Kajabi Order API response
     */
    public static function fromResponse(array $order): self
    {
        return self::fromKajabiOrder($order);
    }

    /**
     * Create Order from Kajabi Order API response
     */
    public static function fromKajabiOrder(array $order): self
    {
        $totalAmount = (float) ($order['attributes']['total_amount'] ?? 0);
        
        // Extract user_id from relationships
        $userId = 0;
        if (isset($order['relationships']['customer']['data']['id'])) {
            $userId = (int) $order['relationships']['customer']['data']['id'];
        }
        
        // Extract product information from order items or relationships
        $productName = '';
        $productId = 0;
        $isSubscription = false;
        $orderItems = [];
        
        // Try to get product info from attributes first
        if (isset($order['attributes']['product_name'])) {
            $productName = $order['attributes']['product_name'];
        }
        
        // Try to get product_id from relationships
        if (isset($order['relationships']['offer']['data']['id'])) {
            $productId = (int) $order['relationships']['offer']['data']['id'];
        }
        
        // Check if it's a subscription based on recurring_payment attribute
        if (isset($order['attributes']['recurring_payment'])) {
            $isSubscription = (bool) $order['attributes']['recurring_payment'];
        }
        
        // Build order items array from available data
        if ($productName || $productId) {
            $orderItems[] = [
                'id' => $productId,
                'name' => $productName,
                'amount' => $totalAmount,
                'type' => $isSubscription ? 'subscription' : 'one_time'
            ];
        }
        
        return new self(
            id: (int) $order['id'],
            created_at: isset($order['attributes']['created_at']) 
                ? Carbon::parse($order['attributes']['created_at']) 
                : Carbon::now(),
            user_id: $userId,
            user_email: $order['attributes']['customer_email'] ?? '',
            user_name: $order['attributes']['customer_name'] ?? '',
            product_name: $productName,
            product_id: $productId,
            amount_dollars: $totalAmount,
            amount_cents: (int) ($totalAmount * 100),
            subscription: $isSubscription,
            coupon_code: $order['attributes']['coupon_code'] ?? null,
            coupon_id: isset($order['attributes']['coupon_id']) ? (int) $order['attributes']['coupon_id'] : null,
            affiliate_referral_code: $order['attributes']['affiliate_code'] ?? null,
            status: $order['attributes']['status'] ?? 'completed',
            items: $orderItems,
            // Kajabi-specific fields
            updated_at: $order['attributes']['updated_at'] ?? null,
            currency: $order['attributes']['currency'] ?? 'USD',
        );
    }
}
