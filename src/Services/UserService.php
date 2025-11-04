<?php

namespace WooNinja\KajabiSaloon\Services;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Paginator;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;
use WooNinja\KajabiSaloon\Requests\Contacts\CreateContact;
use WooNinja\KajabiSaloon\Requests\Contacts\UpdateContact;
use WooNinja\KajabiSaloon\Requests\Contacts\DeleteContact;
use WooNinja\LMSContracts\Contracts\Services\UserServiceInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UserInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Users\CreateUserInterface;
use WooNinja\LMSContracts\Contracts\DTOs\Users\UpdateUserInterface;

class UserService extends Resource implements UserServiceInterface
{
    /**
     * Get a User by ID (maps to Kajabi Contact)
     *
     * @param int $user_id
     * @return UserInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function get(int $user_id): UserInterface
    {
        return $this->connector
            ->send(new GetContact($user_id))
            ->dtoOrFail();
    }

    /**
     * Get a list of Users (maps to Kajabi Contacts)
     * 
     * @param array $filters
     * @return Paginator
     */
    public function users(array $filters = []): Paginator
    {
        return $this->connector
            ->paginate(new GetContacts($filters, $this->getDefaultSiteId()));
    }

    /**
     * Create a User (maps to Kajabi Contact creation)
     *
     * @param CreateUserInterface $user User data
     * @return UserInterface
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function create(CreateUserInterface $user): UserInterface
    {
        // Map Thinkific-style fields to Kajabi format
        $contactData = [
            'email' => $user->email,
            'name' => $user->first_name . ' ' . $user->last_name,
        ];

        // Add optional fields if provided
        // Note: Kajabi doesn't support all Thinkific fields, so we'll add what we can
        // Custom profile fields could be mapped to Kajabi's custom_1, custom_2, custom_3

        return $this->connector
            ->send(new CreateContact($contactData, $this->getDefaultSiteId()))
            ->dtoOrFail();
    }

    /**
     * Update a User (maps to Kajabi Contact update)
     *
     * @param UpdateUserInterface $user
     * @return Response
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function update(UpdateUserInterface $user): Response
    {
        return $this->connector
            ->send(new UpdateContact($user->id, $user));
    }

    /**
     * Delete a User (maps to Kajabi Contact deletion)
     *
     * @param int $user_id
     * @return void
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function delete(int $user_id): void
    {
        $this->connector->send(new DeleteContact($user_id));
    }

    /**
     * Search for a user by ID or email
     *
     * @param string|int $user_id_or_email
     * @return UserInterface|null
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function find(string|int $user_id_or_email): UserInterface|null
    {
        if (is_numeric($user_id_or_email)) {
            try {
                return $this->get($user_id_or_email);
            } catch (\Exception $e) {
                return null;
            }
        }

        return $this->findByEmail($user_id_or_email);
    }

    /**
     * Get a user by exact email
     *
     * @param string $email
     * @return UserInterface|null
     */
    public function findByEmail(string $email): ?UserInterface
    {
        $response = $this->connector->send(new GetContacts([
            'filter[search]' => $email,
            'page[size]' => 1,
        ], $this->getDefaultSiteId()));

        $users = $response->dto();

        if (!empty($users) && count($users) > 0) {
            // Verify exact email match since Kajabi's search might return partial matches
            foreach ($users as $user) {
                if (strtolower($user->email) === strtolower($email)) {
                    return $user;
                }
            }
        }

        return null;
    }
}
