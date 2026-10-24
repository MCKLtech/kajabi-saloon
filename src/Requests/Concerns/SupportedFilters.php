<?php

namespace WooNinja\KajabiSaloon\Requests\Concerns;

/**
 * filter[...] names each list endpoint accepts, from openapi.yaml (spec 1.1.0,
 * SHA-256 77293770...). Kajabi silently ignores any other filter and returns
 * the unfiltered list, so a typo'd or unsupported filter looks like success.
 *
 * Regenerate when the spec pin changes.
 */
final class SupportedFilters
{
    /**
     * Listed in the spec but ignored by the live API (verified 2026-10-24: the
     * unfiltered list came back for every value). Left out of the constants
     * below so strict mode refuses them.
     */
    public const IGNORED_LIVE = [
        'products' => ['status_eq'],
        'courses' => ['publish_status_eq'],
    ];

    /** /v1/contacts and /v1/customers share the same 73 filters */
    public const CONTACTS = [
        'active_in_last', 'address_city_contains', 'address_country_contains', 'address_line_1_contains',
        'address_line_2_contains', 'address_state_contains', 'address_zip_contains',
        'bounced_email_broadcast_id', 'bounced_in_last', 'clicked_email_broadcast_id',
        'clicked_email_in_last', 'complained_in_last', 'completed_assessment_id', 'created_in_last',
        'delivered_email_in_last', 'dropped_email_broadcast_id', 'email_contains', 'failed_assessment_id',
        'has_active_product_id', 'has_all_tag_id', 'has_no_offer_id', 'has_no_product_id', 'has_no_tag_id',
        'has_offer_id', 'has_product_id', 'has_tag_id', 'healthy_contacts_with_open_data',
        'inactive_contacts_with_open_data', 'inactive_in_last', 'is_hard_bouncing', 'is_hidden',
        'joined_in_last', 'manually_unsubscribed_in_last', 'mobile_phone_number_contains', 'name_contains',
        'net_revenue_equal_to', 'net_revenue_greater_than', 'net_revenue_less_than', 'never_subscribed',
        'no_bounced_email_broadcast_id', 'no_clicked_email_broadcast_id', 'no_delivered_email_broadcast_id',
        'no_dropped_email_broadcast_id', 'no_opened_email_broadcast_id', 'no_sent_email_broadcast_id',
        'no_sent_email_broadcast_ids', 'no_submitted_form_id', 'not_clicked_email_in_last',
        'not_created_in_last', 'not_delivered_email_in_last', 'not_opened_email_in_last',
        'not_registered_event_id', 'not_subscribed_email_sequence_id', 'opened_email_broadcast_id',
        'opened_email_in_last', 'opted_out_in_last', 'passed_assessment_id',
        'passive_contacts_with_open_data', 'phone_number_contains', 'previously_owned_product_id',
        'registered_event_id', 'search', 'sent_email_broadcast_id', 'site_id', 'submitted_form_id',
        'subscribed', 'subscribed_email_sequence_id', 'subscribed_in_last', 'subscribed_newsletter_id',
        'unengaged_contacts_with_open_data', 'unsubscribed_in_last', 'unsubscribed_newsletter_id',
        'used_coupon_code',
    ];

    public const COURSES = [
        'description_cont', 'site_id', 'title_cont',
    ];

    public const CUSTOM_FIELDS = [
        'required_eq', 'site_id', 'title_cont', 'type_eq',
    ];

    public const OFFERS = [
        'description_cont', 'site_id', 'title_cont',
    ];

    public const ORDERS = [
        'customer_id', 'fulfilled_at_null', 'order_number_eq', 'site_id',
    ];

    public const PRODUCTS = [
        'description_cont', 'exclude_product_types', 'site_id', 'title_cont',
    ];

    public const PURCHASES = [
        'active', 'coupon_code_eq', 'customer_id', 'deactivated', 'referrer', 'site_id',
    ];

    public const SITES = [
        'subdomain_cont', 'title_cont',
    ];

    public const HOOKS = [
        'event_eq',
    ];

    public const CUSTOMERS = self::CONTACTS;

    /** /v1/course_progress (from Kajabi's developer docs; not in openapi.yaml) */
    public const COURSE_PROGRESS = ['product_id', 'customer_id', 'updated_since'];

    /**
     * Top-level JSON:API parameters Kajabi understands besides filter[...]
     */
    public const PARAMETERS = ['page', 'fields', 'include', 'sort'];

    /**
     * Everything in $query Kajabi would ignore: unsupported filter[...] names
     * (reported bare, e.g. "id") and non-JSON:API parameters (reported as sent,
     * e.g. "query[user_id]")
     *
     * @param string[] $supported
     * @return string[]
     */
    public static function ignored(array $query, array $supported): array
    {
        $ignored = self::unsupported($query, $supported);

        foreach (array_keys($query) as $key) {
            $root = strtok((string) $key, '[');

            if ($root !== 'filter' && !in_array($root, self::PARAMETERS, true)) {
                $ignored[] = (string) $key;
            }
        }

        return array_values(array_unique($ignored));
    }

    /**
     * filter[...] names in $query that aren't in $supported
     *
     * @param array<string, mixed> $query Flat query, e.g. ['filter[site_id]' => 1, 'filter[tags][]' => 'a']
     * @param string[] $supported
     * @return string[]
     */
    public static function unsupported(array $query, array $supported): array
    {
        $allowed = array_flip($supported);
        $unsupported = [];

        foreach (array_keys($query) as $key) {
            if (preg_match('/^filter\[([^\]]+)\]/', (string) $key, $match) && !isset($allowed[$match[1]])) {
                $unsupported[] = $match[1];
            }
        }

        return array_values(array_unique($unsupported));
    }
}
