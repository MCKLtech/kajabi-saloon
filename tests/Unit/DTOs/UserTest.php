<?php

namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;
use Tests\Fixtures\KajabiApiResponses;

class UserTest extends TestCase
{
    public function test_implements_user_interface(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertInstanceOf(UserInterface::class, $user);
    }

    public function test_creates_user_from_kajabi_contact(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertEquals(123, $user->id);
        $this->assertEquals('john.doe@example.com', $user->email);
        $this->assertEquals('John', $user->first_name);
        $this->assertEquals('Doe', $user->last_name);
    }

    public function test_splits_name_correctly(): void
    {
        $contactData = KajabiApiResponses::contact();

        // Test single name
        $contactData['attributes']['name'] = 'Madonna';
        $user = User::fromKajabiContact($contactData);
        $this->assertEquals('Madonna', $user->first_name);
        $this->assertEquals('', $user->last_name);

        // Test multi-word last name (known limitation)
        $contactData['attributes']['name'] = 'John von Neumann';
        $user = User::fromKajabiContact($contactData);
        $this->assertEquals('John', $user->first_name);
        $this->assertEquals('von Neumann', $user->last_name);
    }

    public function test_maps_custom_fields(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertIsArray($user->custom_profile_fields);
        $this->assertEquals('Custom Value 1', $user->custom_profile_fields['custom_1']);
        $this->assertEquals('Custom Value 2', $user->custom_profile_fields['custom_2']);
        $this->assertNull($user->custom_profile_fields['custom_3']);
    }

    public function test_sets_default_role_to_student(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertIsArray($user->roles);
        $this->assertContains('student', $user->roles);
    }

    public function test_kajabi_specific_fields_are_populated(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertEquals('+1-555-0123', $user->phone_number);
        $this->assertEquals('123 Main St', $user->address_line_1);
        $this->assertEquals('Apt 4B', $user->address_line_2);
        $this->assertEquals('New York', $user->address_city);
        $this->assertEquals('NY', $user->address_state);
        $this->assertEquals('USA', $user->address_country);
        $this->assertEquals('10001', $user->address_zip);
        $this->assertFalse($user->subscribed);
    }

    public function test_thinkific_incompatible_fields_are_null(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        // These fields don't exist in Kajabi
        $this->assertNull($user->bio);
        $this->assertNull($user->company);
        $this->assertNull($user->headline);
        $this->assertNull($user->avatar_url);
        $this->assertNull($user->affiliate_code);
        $this->assertNull($user->affiliate_commission);
    }

    public function test_get_full_name_method(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertEquals('John Doe', $user->getFullName());

        // Test with empty name
        $contactData['attributes']['name'] = '';
        $user = User::fromKajabiContact($contactData);
        $this->assertEquals('john.doe@example.com', $user->getFullName());
    }

    public function test_timestamps_are_preserved(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        $this->assertEquals('2024-01-15T10:30:00Z', $user->created_at);
        $this->assertEquals('2024-01-16T14:20:00Z', $user->updated_at);
    }

    public function test_password_is_always_null_from_api(): void
    {
        $contactData = KajabiApiResponses::contact();
        $user = User::fromKajabiContact($contactData);

        // Kajabi never exposes passwords in API responses
        $this->assertNull($user->password);
    }
}
