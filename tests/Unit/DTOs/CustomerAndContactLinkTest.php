<?php

namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\KajabiApiResponses;
use Tests\Fixtures\MemberActivityPayloads;
use WooNinja\KajabiSaloon\DataTransferObjects\Customers\Customer;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;

/**
 * Contact <-> Customer links used to resolve course progress for a user
 */
class CustomerAndContactLinkTest extends TestCase
{
    public function test_customer_maps_contact_site_and_last_sign_in(): void
    {
        $customer = Customer::fromKajabiCustomer(MemberActivityPayloads::customer());

        $this->assertSame(6000001, $customer->contact_id);
        $this->assertSame(111111, $customer->site_id);
        $this->assertSame('2026-10-20T18:55:02.118Z', $customer->last_sign_in_at);
    }

    public function test_customer_without_relationships_defaults_to_null(): void
    {
        $customer = Customer::fromKajabiCustomer(KajabiApiResponses::customer());

        $this->assertNull($customer->contact_id);
        $this->assertNull($customer->site_id);
        $this->assertNull($customer->last_sign_in_at);
        $this->assertSame(456, $customer->id);
    }

    public function test_user_maps_customer_id_from_contact_relationship(): void
    {
        $this->assertSame(456, User::fromKajabiContact(KajabiApiResponses::contact())->customer_id);
    }

    public function test_user_without_customer_relationship(): void
    {
        $contact = KajabiApiResponses::contact();
        unset($contact['relationships']['customer']);

        $this->assertNull(User::fromKajabiContact($contact)->customer_id);
    }
}
