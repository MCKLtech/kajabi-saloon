<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\KajabiApiResponses;
use Tests\Fixtures\MemberActivityPayloads;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\Webhooks\Webhook;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;
use WooNinja\KajabiSaloon\Requests\Webhooks\CreateWebhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\DeleteWebhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhook;
use WooNinja\KajabiSaloon\Requests\Webhooks\GetWebhookSample;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\KajabiSaloon\Services\WebhookService;
use WooNinja\KajabiSaloon\Webhooks\Events\LessonCompleted;

class WebhookServiceTest extends TestCase
{
    private function serviceWithMock(MockClient $mockClient, ?string $siteId = '111111'): WebhookService
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', $siteId);
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));

        return new WebhookService($kajabi);
    }

    public function test_webhook_dto_maps_kajabi_hook_fields(): void
    {
        $hook = Webhook::fromKajabiWebhook(MemberActivityPayloads::hook(['resource_id' => 2000001]));

        $this->assertSame(990001, $hook->id);
        $this->assertSame('https://hooks.example.com/kajabi/lesson-completed', $hook->target_url);
        $this->assertSame($hook->target_url, $hook->url, 'url mirrors target_url for backward compatibility');
        $this->assertSame('lesson_completed', $hook->event);
        $this->assertSame(WebhookEvent::LessonCompleted, $hook->eventType());
        $this->assertSame(2000001, $hook->resource_id);
        $this->assertTrue($hook->isCourseScoped());
        $this->assertSame(111111, $hook->site_id);
        $this->assertTrue($hook->active);
        $this->assertNull($hook->secret);
        $this->assertSame('2026-10-20T19:00:00.000Z', $hook->created_at);
    }

    public function test_webhook_dto_unscoped_and_legacy_url_attribute(): void
    {
        $hook = Webhook::fromKajabiWebhook(MemberActivityPayloads::hook());
        $this->assertNull($hook->resource_id);
        $this->assertFalse($hook->isCourseScoped());

        // Older fixture shape with a plain url attribute and unknown event
        $legacy = Webhook::fromKajabiWebhook(KajabiApiResponses::webhook());
        $this->assertSame('https://example.com/webhooks/kajabi', $legacy->url);
        $this->assertNull($legacy->eventType());
        $this->assertNull($legacy->site_id);
    }

    public function test_create_sends_json_api_body(): void
    {
        $mockClient = new MockClient([
            CreateWebhook::class => MockResponse::make(['data' => MemberActivityPayloads::hook()], 201),
        ]);

        $hook = $this->serviceWithMock($mockClient)
            ->create(WebhookEvent::LessonCompleted, 'https://hooks.example.com/kajabi/lesson-completed');

        $request = $mockClient->getLastPendingRequest();
        $this->assertSame(Method::POST, $request->getMethod());
        $this->assertStringEndsWith('/hooks', $request->getUrl());
        $this->assertSame('application/vnd.api+json', $request->headers()->get('Content-Type'));
        $this->assertSame([
            'data' => [
                'type' => 'hooks',
                'attributes' => [
                    'target_url' => 'https://hooks.example.com/kajabi/lesson-completed',
                    'event' => 'lesson_completed',
                ],
                'relationships' => [
                    'site' => ['data' => ['type' => 'sites', 'id' => '111111']],
                ],
            ],
        ], $request->body()->all());

        $this->assertSame(990001, $hook->id);
    }

    public function test_create_scoped_to_course_with_string_event_and_explicit_site(): void
    {
        $mockClient = new MockClient([
            CreateWebhook::class => MockResponse::make(['data' => MemberActivityPayloads::hook(['event' => 'course_started', 'resource_id' => 2000001])], 201),
        ]);

        $hook = $this->serviceWithMock($mockClient, null)
            ->create('course_started', 'https://hooks.example.com/kajabi/course-started', 2000001, '222222');

        $body = $mockClient->getLastPendingRequest()->body()->all();
        $this->assertSame('2000001', $body['data']['attributes']['resource_id']);
        $this->assertSame('course_started', $body['data']['attributes']['event']);
        $this->assertSame('222222', $body['data']['relationships']['site']['data']['id']);
        $this->assertSame(2000001, $hook->resource_id);
    }

    public function test_create_rejects_http_url_without_a_request(): void
    {
        $mockClient = new MockClient([]);

        try {
            $this->serviceWithMock($mockClient)->create(WebhookEvent::LessonCompleted, 'http://hooks.example.com/x');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('https://', $e->getMessage());
        }

        $this->assertNull($mockClient->getLastPendingRequest());
    }

    public function test_create_rejects_course_scope_on_unscopable_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("member_signed_in event can't be scoped");

        $this->serviceWithMock(new MockClient([]))
            ->create(WebhookEvent::MemberSignedIn, 'https://hooks.example.com/x', 2000001);
    }

    public function test_create_requires_a_site_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('site_id is required');

        $this->serviceWithMock(new MockClient([]), null)
            ->create(WebhookEvent::LessonCompleted, 'https://hooks.example.com/x');
    }

    public function test_create_rejects_unknown_event_string(): void
    {
        $this->expectException(\ValueError::class);

        $this->serviceWithMock(new MockClient([]))->create('lesson_done', 'https://hooks.example.com/x');
    }

    public function test_delete(): void
    {
        $mockClient = new MockClient([
            DeleteWebhook::class => MockResponse::make('', 204),
        ]);

        $response = $this->serviceWithMock($mockClient)->delete(990001);

        $this->assertSame(204, $response->status());
        $request = $mockClient->getLastPendingRequest();
        $this->assertSame(Method::DELETE, $request->getMethod());
        $this->assertStringEndsWith('/hooks/990001', $request->getUrl());
    }

    public function test_replace_creates_new_hook_before_deleting_old(): void
    {
        $old = Webhook::fromKajabiWebhook(MemberActivityPayloads::hook(['resource_id' => 2000001]));
        $newHook = MemberActivityPayloads::hook(['target_url' => 'https://hooks.example.com/new', 'resource_id' => 2000001]);
        $newHook['id'] = '990002';

        $mockClient = new MockClient([
            MockResponse::make(['data' => $newHook], 201),
            MockResponse::make('', 204),
        ]);

        $replacement = $this->serviceWithMock($mockClient, null)->replace($old, 'https://hooks.example.com/new');

        $this->assertSame(990002, $replacement->id);

        $mockClient->assertSentCount(2);
        $recorded = $mockClient->getRecordedResponses();
        $this->assertInstanceOf(CreateWebhook::class, $recorded[0]->getRequest());
        $this->assertInstanceOf(DeleteWebhook::class, $recorded[1]->getRequest());
        $this->assertStringEndsWith('/hooks/990001', $recorded[1]->getPendingRequest()->getUrl());

        // Event, course scope and site carried over from the old hook
        $body = $recorded[0]->getPendingRequest()->body()->all();
        $this->assertSame('lesson_completed', $body['data']['attributes']['event']);
        $this->assertSame('2000001', $body['data']['attributes']['resource_id']);
        $this->assertSame('111111', $body['data']['relationships']['site']['data']['id']);
    }

    public function test_replace_by_id_fetches_hook_first(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['data' => MemberActivityPayloads::hook()]),
            MockResponse::make(['data' => MemberActivityPayloads::hook(['target_url' => 'https://hooks.example.com/new'])], 201),
            MockResponse::make('', 204),
        ]);

        $this->serviceWithMock($mockClient)->replace(990001, 'https://hooks.example.com/new');

        $recorded = $mockClient->getRecordedResponses();
        $this->assertInstanceOf(GetWebhook::class, $recorded[0]->getRequest());
        $this->assertInstanceOf(CreateWebhook::class, $recorded[1]->getRequest());
        $this->assertInstanceOf(DeleteWebhook::class, $recorded[2]->getRequest());
    }

    public function test_replace_does_not_delete_when_create_fails(): void
    {
        $old = Webhook::fromKajabiWebhook(MemberActivityPayloads::hook());

        $mockClient = new MockClient([
            MockResponse::make(['errors' => [['detail' => 'invalid']]], 422),
        ]);

        try {
            $this->serviceWithMock($mockClient)->replace($old, 'https://hooks.example.com/new');
            $this->fail('Expected a request exception');
        } catch (\Saloon\Exceptions\Request\RequestException) {
        }

        $mockClient->assertSentCount(1);
    }

    public function test_sample_returns_payload_array(): void
    {
        $payload = MemberActivityPayloads::lessonCompleted()['payload'];
        $mockClient = new MockClient([
            GetWebhookSample::class => MockResponse::make($payload),
        ]);

        $sample = $this->serviceWithMock($mockClient)->sample('lesson_completed');

        $this->assertSame($payload, $sample);
        $this->assertStringEndsWith('/hooks/lesson_completed_sample', $mockClient->getLastPendingRequest()->getUrl());
    }

    public function test_sample_tolerates_data_wrapper(): void
    {
        $payload = MemberActivityPayloads::memberSignedIn()['payload'];
        $mockClient = new MockClient([
            GetWebhookSample::class => MockResponse::make(['data' => $payload]),
        ]);

        $this->assertSame($payload, $this->serviceWithMock($mockClient)->sample(WebhookEvent::MemberSignedIn));
    }

    public function test_sample_event_parses_into_typed_event(): void
    {
        $mockClient = new MockClient([
            GetWebhookSample::class => MockResponse::make(MemberActivityPayloads::lessonCompleted()['payload']),
        ]);

        $event = $this->serviceWithMock($mockClient)->sampleEvent(WebhookEvent::LessonCompleted);

        $this->assertInstanceOf(LessonCompleted::class, $event);
        $this->assertSame(3000001, $event->lessonId());
    }

    public function test_parse_delegates_to_parser(): void
    {
        $event = $this->serviceWithMock(new MockClient([]))->parse(MemberActivityPayloads::lessonCompleted());

        $this->assertInstanceOf(LessonCompleted::class, $event);
    }

    public function test_webhooks_list_maps_event_filter(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['data' => [MemberActivityPayloads::hook()]]),
        ]);

        $hooks = iterator_to_array(
            $this->serviceWithMock($mockClient)->webhooks(['event' => WebhookEvent::LessonCompleted])->items(),
            false
        );

        $this->assertCount(1, $hooks);
        $this->assertSame('lesson_completed', $mockClient->getLastPendingRequest()->query()->get('filter[event_eq]'));
    }
}
