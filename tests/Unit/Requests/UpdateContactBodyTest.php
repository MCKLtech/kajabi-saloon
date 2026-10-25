<?php

namespace Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\UpdateUser;
use WooNinja\KajabiSaloon\Requests\Contacts\UpdateContact;

class UpdateContactBodyTest extends TestCase
{
    private static function body(UpdateUser $user): array
    {
        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        (new KajabiConnector())->withMockClient($mockClient)->send(new UpdateContact($user->id, $user));

        return $mockClient->getLastPendingRequest()->body()->all();
    }

    public function test_full_update_sends_name_and_email(): void
    {
        $body = self::body(new UpdateUser(id: 5, first_name: 'Ada', last_name: 'Lovelace', email: 'ada@example.com'));

        $this->assertSame('5', $body['data']['id']);
        $this->assertSame('contacts', $body['data']['type']);
        $this->assertSame(['name' => 'Ada Lovelace', 'email' => 'ada@example.com'], $body['data']['attributes']);
    }

    public function test_empty_name_and_email_are_not_sent(): void
    {
        $body = self::body(new UpdateUser(id: 5, first_name: '', last_name: ' ', email: '', external_source: 'ext-1'));

        $this->assertSame(['external_user_id' => 'ext-1'], $body['data']['attributes'], 'nothing blanked');
    }
}
