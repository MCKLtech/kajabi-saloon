<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Auth\KajabiAuthenticator;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContact;
use WooNinja\KajabiSaloon\Requests\Contacts\GetContacts;

/**
 * Credential-free smoke tests proving the two riskiest Saloon v4 migration
 * surfaces still work at runtime: the custom JSON:API CursorPaginator and the
 * OAuth2 client-credentials authenticator. No network calls - MockClient only.
 */
class SaloonV4SmokeTest extends TestCase
{
    private function contact(int $id, string $email): array
    {
        return [
            'id' => (string) $id,
            'type' => 'contacts',
            'attributes' => [
                'name' => 'User ' . $id,
                'email' => $email,
            ],
            'relationships' => [
                'site' => ['data' => ['id' => '1', 'type' => 'sites']],
                'customer' => ['data' => ['id' => (string) (100 + $id), 'type' => 'customers']],
            ],
        ];
    }

    public function test_paginator_iterates_all_pages_and_converts_dtos(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([
                'data' => [$this->contact(1, 'a@example.com'), $this->contact(2, 'b@example.com')],
                'meta' => ['total' => 3],
                'links' => [
                    'next' => 'https://api.kajabi.com/v1/contacts?page[number]=2&page[size]=2',
                ],
            ]),
            MockResponse::make([
                'data' => [$this->contact(3, 'c@example.com')],
                'meta' => ['total' => 3],
                'links' => [],
            ]),
        ]);

        $connector = (new KajabiConnector())->withMockClient($mockClient);
        $paginator = $connector->paginate(new GetContacts(['limit' => 2]));

        $emails = [];
        foreach ($paginator->items() as $user) {
            $this->assertInstanceOf(User::class, $user);
            $emails[] = $user->email;
        }

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $emails);
        $this->assertSame(3, $paginator->getTotalAPIResults());

        // The final request must have carried page[number]=2 parsed from links.next
        // (parse_str nests it as page[number] => ['number' => '2', 'size' => '2'])
        $lastQuery = $mockClient->getLastRequest()->query()->all();
        $this->assertSame('2', $lastQuery['page']['number'] ?? null);
    }

    public function test_oauth2_authenticator_fetches_token_and_applies_bearer_header(): void
    {
        $mockClient = new MockClient([
            MockResponse::make([
                'access_token' => 'test-access-token',
                'token_type' => 'bearer',
                'expires_in' => 3600,
            ]),
            MockResponse::make([
                'data' => $this->contact(1, 'a@example.com'),
            ]),
        ]);

        $authenticator = new KajabiAuthenticator('client-id', 'client-secret');
        $connector = (new KajabiConnector())
            ->authenticate($authenticator)
            ->withMockClient($mockClient);
        $authenticator->connector = $connector;

        $user = $connector->send(new GetContact(1))->dtoOrFail();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('a@example.com', $user->email);

        // The token fetch must have happened (GetToken via MockClient) and the
        // Authorization header must have been applied to the GetContact request.
        $this->assertSame('test-access-token', $authenticator->getAccessToken());
        $this->assertSame(
            'Bearer test-access-token',
            $mockClient->getLastPendingRequest()->headers()->get('Authorization')
        );
    }
}
