<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use WooNinja\KajabiSaloon\Services\UserService;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\LMSContracts\Contracts\Services\UserServiceInterface;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\CreateUser;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\UpdateUser;

class UserServiceTest extends TestCase
{
    private UserService $userService;

    protected function setUp(): void
    {
        // Create a real service instance for testing
        $kajabiService = new KajabiService(
            'test-client-id',
            'test-client-secret',
            'test-site-123'
        );

        $this->userService = $kajabiService->users;
    }

    public function test_implements_user_service_interface(): void
    {
        $this->assertInstanceOf(UserServiceInterface::class, $this->userService);
    }

    public function test_has_required_interface_methods(): void
    {
        $this->assertTrue(method_exists($this->userService, 'get'));
        $this->assertTrue(method_exists($this->userService, 'users'));
        $this->assertTrue(method_exists($this->userService, 'create'));
        $this->assertTrue(method_exists($this->userService, 'update'));
        $this->assertTrue(method_exists($this->userService, 'delete'));
        $this->assertTrue(method_exists($this->userService, 'find'));
        $this->assertTrue(method_exists($this->userService, 'findByEmail'));
    }

    public function test_create_user_dto_is_valid(): void
    {
        $createUser = new CreateUser(
            first_name: 'John',
            last_name: 'Doe',
            email: 'john.doe@example.com'
        );

        $this->assertEquals('John', $createUser->first_name);
        $this->assertEquals('Doe', $createUser->last_name);
        $this->assertEquals('john.doe@example.com', $createUser->email);
    }

    public function test_update_user_dto_is_valid(): void
    {
        $updateUser = new UpdateUser(
            id: 123,
            first_name: 'Jane',
            last_name: 'Doe',
            email: 'jane.doe@example.com'
        );

        $this->assertEquals(123, $updateUser->id);
        $this->assertEquals('Jane', $updateUser->first_name);
        $this->assertEquals('Doe', $updateUser->last_name);
        $this->assertEquals('jane.doe@example.com', $updateUser->email);
    }

    public function test_create_user_supports_custom_fields(): void
    {
        $createUser = new CreateUser(
            first_name: 'John',
            last_name: 'Doe',
            email: 'john.doe@example.com',
            custom_profile_fields: [
                ['label' => 'Company', 'value' => 'Acme Inc'],
                ['label' => 'Department', 'value' => 'Marketing'],
            ]
        );

        $this->assertNotNull($createUser->custom_profile_fields);
        $this->assertCount(2, $createUser->custom_profile_fields);
    }

    public function test_update_user_supports_external_source(): void
    {
        $updateUser = new UpdateUser(
            id: 123,
            first_name: 'John',
            last_name: 'Doe',
            email: 'john.doe@example.com',
            external_source: 'external_123'
        );

        $this->assertEquals('external_123', $updateUser->external_source);
    }
}
