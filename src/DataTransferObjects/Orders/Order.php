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
        public ?float  $total = null,
        public ?int    $order_number = null,
        public ?string $fulfilled_at = null,
        public ?int    $subtotal_cents = null,
        public ?int    $sales_tax_cents = null,
        public ?int    $discount_cents = null,
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
     *
     * Live shape (verified 2026-10-24): amounts are integer cents
     * (total_price_in_cents, subtotal_in_cents, ...), currency_code,
     * order_number and fulfilled_at; relationships customer and order_items.
     * There is no status, coupon or customer email on the order itself: items
     * and the customer's email/name come from `included` when requested with
     * include=order_items,customer (GetOrder/GetOrders do by default).
     * Older attribute names are still read as fallbacks. An order from a
     * customer since deleted keeps the customer relationship, but Kajabi leaves
     * the customer out of `included`, so user_email/user_name are ''.
     *
     * @param array $includedMap 'type:id' => resource, from the response's `included`
     */
    public static function fromKajabiOrder(array $order, array $includedMap = []): self
    {
        $attributes = $order['attributes'] ?? [];

        $amountCents = isset($attributes['total_price_in_cents'])
            ? (int) $attributes['total_price_in_cents']
            : (int) round((float) ($attributes['total_amount'] ?? $attributes['total'] ?? 0) * 100);
        $totalAmount = $amountCents / 100;

        $userId = (int) ($order['relationships']['customer']['data']['id'] ?? 0);
        $customer = $includedMap["customers:{$userId}"]['attributes'] ?? [];

        $orderItems = [];
        foreach ($order['relationships']['order_items']['data'] ?? [] as $identifier) {
            $item = $includedMap["order_items:{$identifier['id']}"]['attributes'] ?? null;

            if ($item === null) {
                continue;
            }

            $orderItems[] = [
                'id' => (int) ($item['item_id'] ?? 0),
                'name' => $item['title'] ?? '',
                'amount' => isset($item['total_price_in_cents']) ? $item['total_price_in_cents'] / 100 : 0.0,
                'type' => $item['item_type'] ?? null,        // "Offer" for every item seen live
                'quantity' => (int) ($item['quantity'] ?? 1),
                'purchase_id' => isset($item['purchase_id']) ? (int) $item['purchase_id'] : null,
                'order_item_id' => (int) $identifier['id'],
            ];
        }

        // Product = the first item's offer (Thinkific compatibility: course/product id = offer id)
        $productName = $orderItems[0]['name'] ?? $attributes['product_name'] ?? '';
        $productId = $orderItems[0]['id'] ?? (int) ($order['relationships']['offer']['data']['id'] ?? 0);
        $isSubscription = (bool) ($attributes['recurring_payment'] ?? false);

        if ($orderItems === [] && ($productName || $productId)) {
            $orderItems[] = [
                'id' => $productId,
                'name' => $productName,
                'amount' => $totalAmount,
                'type' => $isSubscription ? 'subscription' : 'one_time',
            ];
        }

        // No status attribute live: an order sent with fulfilled_at = null is pending.
        // Responses without the field at all (older shapes) keep the old 'completed' default.
        $fulfilledAt = $attributes['fulfilled_at'] ?? null;
        $isUnfulfilled = array_key_exists('fulfilled_at', $attributes) && $fulfilledAt === null;
        $status = $attributes['status'] ?? ($isUnfulfilled ? 'pending' : 'completed');

        return new self(
            id: (int) $order['id'],
            created_at: isset($attributes['created_at'])
                ? Carbon::parse($attributes['created_at'])
                : Carbon::now(),
            user_id: $userId,
            user_email: $customer['email'] ?? $attributes['customer_email'] ?? '',
            user_name: $customer['name'] ?? $attributes['customer_name'] ?? '',
            product_name: $productName,
            product_id: $productId,
            amount_dollars: $totalAmount,
            amount_cents: $amountCents,
            subscription: $isSubscription,
            coupon_code: $attributes['coupon_code'] ?? null,
            coupon_id: isset($attributes['coupon_id']) ? (int) $attributes['coupon_id'] : null,
            affiliate_referral_code: $attributes['affiliate_code'] ?? null,
            status: $status,
            items: $orderItems,
            // Kajabi-specific fields
            updated_at: $attributes['updated_at'] ?? null,
            currency: $attributes['currency_code'] ?? $attributes['currency'] ?? 'USD',
            total: $totalAmount,
            order_number: isset($attributes['order_number']) ? (int) $attributes['order_number'] : null,
            fulfilled_at: $fulfilledAt,
            subtotal_cents: isset($attributes['subtotal_in_cents']) ? (int) $attributes['subtotal_in_cents'] : null,
            sales_tax_cents: isset($attributes['sales_tax_amount_in_cents']) ? (int) $attributes['sales_tax_amount_in_cents'] : null,
            discount_cents: isset($attributes['discount_amount_in_cents']) ? (int) $attributes['discount_amount_in_cents'] : null,
        );
    }

    /**
     * 'type:id' => resource for a response's `included` array
     */
    public static function includedMap(array $included): array
    {
        $map = [];

        foreach ($included as $resource) {
            if (isset($resource['type'], $resource['id'])) {
                $map["{$resource['type']}:{$resource['id']}"] = $resource;
            }
        }

        return $map;
    }
}
