<?php

namespace Tests\Unit\Webhooks;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\MemberActivityPayloads;
use WooNinja\KajabiSaloon\DataTransferObjects\Courses\Course;
use WooNinja\KajabiSaloon\DataTransferObjects\Customers\Customer;
use WooNinja\KajabiSaloon\DataTransferObjects\Users\User;
use WooNinja\KajabiSaloon\Enums\WebhookEvent;
use WooNinja\KajabiSaloon\Exceptions\InvalidWebhookPayloadException;
use WooNinja\KajabiSaloon\Webhooks\Events\AssessmentCompleted;
use WooNinja\KajabiSaloon\Webhooks\Events\CourseCompleted;
use WooNinja\KajabiSaloon\Webhooks\Events\CourseStarted;
use WooNinja\KajabiSaloon\Webhooks\Events\GenericWebhookEvent;
use WooNinja\KajabiSaloon\Webhooks\Events\LessonCompleted;
use WooNinja\KajabiSaloon\Webhooks\Events\MemberSignedIn;
use WooNinja\KajabiSaloon\Webhooks\WebhookDelivery;
use WooNinja\KajabiSaloon\Webhooks\WebhookParser;

class WebhookParserTest extends TestCase
{
    public function test_lesson_completed(): void
    {
        $event = WebhookParser::parse(MemberActivityPayloads::lessonCompleted());

        $this->assertInstanceOf(LessonCompleted::class, $event);
        $this->assertSame('6b1f2c3e-0000-4000-8000-000000000001', $event->deliveryId());
        $this->assertSame('lesson_completed', $event->eventName());
        $this->assertSame(WebhookEvent::LessonCompleted, $event->eventType());

        $this->assertInstanceOf(Customer::class, $event->customer);
        $this->assertSame(5000001, $event->customerId());
        $this->assertSame(6000001, $event->contactId());
        $this->assertSame('jane@example.com', $event->customer->email);
        $this->assertSame('2026-10-20T18:55:02.118Z', $event->customer->last_sign_in_at);
        $this->assertSame(111111, $event->customer->site_id);

        $this->assertSame(3000001, $event->lessonId());
        $this->assertSame('Welcome and setup', $event->lesson->title);
        $this->assertSame(1, $event->lesson->position);
        $this->assertSame('published', $event->lesson->status);
        $this->assertSame(4000001, $event->lesson->module_id);
        $this->assertNull($event->lesson->media_id);

        $this->assertInstanceOf(Course::class, $event->course);
        $this->assertSame(2000001, $event->courseId());
        $this->assertSame('Example Course', $event->course->name);
        $this->assertSame([3000001, 3000002], $event->courseLessonIds());

        $this->assertTrue(Carbon::parse('2026-10-20T19:04:11.000Z')->eq($event->occurredAt()));
        $this->assertSame(
            'lesson_completed:5000001:3000001:2026-10-20T19:04:11.000Z',
            $event->contentKey()
        );
    }

    public function test_lesson_with_media_relationship(): void
    {
        $body = MemberActivityPayloads::lessonCompleted();
        $body['payload'][1] = MemberActivityPayloads::lesson(withMedia: true);

        $event = WebhookParser::parse($body);

        $this->assertSame(4500001, $event->lesson->media_id);
    }

    public function test_course_started(): void
    {
        $event = WebhookParser::parse(MemberActivityPayloads::courseStarted());

        $this->assertInstanceOf(CourseStarted::class, $event);
        $this->assertSame(2000001, $event->courseId());
        $this->assertTrue(Carbon::parse('2026-10-20T19:04:12.207Z')->eq($event->occurredAt()));
        $this->assertSame('course_started:5000001:2000001:2026-10-20T19:04:12.207Z', $event->contentKey());
    }

    public function test_course_completed(): void
    {
        $event = WebhookParser::parse(MemberActivityPayloads::courseCompleted());

        $this->assertInstanceOf(CourseCompleted::class, $event);
        $this->assertTrue(Carbon::parse('2026-10-20T19:20:31.845Z')->eq($event->occurredAt()));
        $this->assertSame('course_completed:5000001:2000001:2026-10-20T19:20:31.845Z', $event->contentKey());
    }

    public function test_assessment_completed(): void
    {
        $event = WebhookParser::parse(MemberActivityPayloads::assessmentCompleted());

        $this->assertInstanceOf(AssessmentCompleted::class, $event);
        $this->assertInstanceOf(User::class, $event->contact);
        $this->assertSame(6000001, $event->contactId());
        $this->assertSame(5000001, $event->customerId());
        $this->assertSame('Jane', $event->contact->first_name);
        $this->assertSame('Example', $event->contact->last_name);

        $this->assertSame(8000001, $event->assessment->id);
        $this->assertSame('Module 1 quiz', $event->assessment->title);

        $this->assertSame(9000001, $event->result->id);
        $this->assertTrue($event->result->passed);
        $this->assertSame(80, $event->result->grade);
        $this->assertTrue($event->result->isGraded());

        $this->assertTrue(Carbon::parse('2026-10-20T19:30:04.902Z')->eq($event->occurredAt()));
        $this->assertSame('assessment_completed:9000001', $event->contentKey());
    }

    public function test_ungraded_assessment_has_null_passed_and_grade(): void
    {
        $event = WebhookParser::parse(MemberActivityPayloads::assessmentCompleted(passed: null, grade: null));

        $this->assertNull($event->result->passed);
        $this->assertNull($event->result->grade);
        $this->assertFalse($event->result->isGraded());
    }

    public function test_failed_assessment(): void
    {
        $event = WebhookParser::parse(MemberActivityPayloads::assessmentCompleted(passed: false, grade: 0));

        $this->assertFalse($event->result->passed);
        $this->assertSame(0, $event->result->grade);
        $this->assertTrue($event->result->isGraded());
    }

    public function test_assessment_contact_without_customer(): void
    {
        $body = MemberActivityPayloads::assessmentCompleted();
        unset($body['payload'][0]['relationships']['customer']);

        $this->assertNull(WebhookParser::parse($body)->customerId());
    }

    public function test_member_signed_in(): void
    {
        $event = WebhookParser::parse(MemberActivityPayloads::memberSignedIn());

        $this->assertInstanceOf(MemberSignedIn::class, $event);
        $this->assertSame(5000001, $event->customerId());
        $this->assertTrue(Carbon::parse('2026-10-20T18:55:02.118Z')->eq($event->occurredAt()));
        $this->assertSame('member_signed_in:5000001:2026-10-20T18:55:02.118Z', $event->contentKey());
    }

    public function test_resources_are_matched_by_type_not_position(): void
    {
        $body = MemberActivityPayloads::lessonCompleted();
        $body['payload'] = array_reverse($body['payload']);

        $event = WebhookParser::parse($body);

        $this->assertSame(5000001, $event->customerId());
        $this->assertSame(3000001, $event->lessonId());
        $this->assertSame(2000001, $event->courseId());
    }

    public function test_parses_raw_json_body(): void
    {
        $event = WebhookParser::parse(json_encode(MemberActivityPayloads::courseStarted()));

        $this->assertInstanceOf(CourseStarted::class, $event);
    }

    public function test_duplicate_copies_share_content_key_but_not_delivery_id(): void
    {
        $first = WebhookParser::parse(MemberActivityPayloads::lessonCompleted('delivery-a'));
        $second = WebhookParser::parse(MemberActivityPayloads::lessonCompleted('delivery-b'));

        $this->assertNotSame($first->deliveryId(), $second->deliveryId());
        $this->assertSame($first->contentKey(), $second->contentKey());
    }

    public function test_re_mark_produces_new_content_key(): void
    {
        $remark = MemberActivityPayloads::lessonCompleted();
        $remark['payload'][0]['meta']['completed_at'] = '2026-10-21T09:00:00.000Z';

        $this->assertNotSame(
            WebhookParser::parse(MemberActivityPayloads::lessonCompleted())->contentKey(),
            WebhookParser::parse($remark)->contentKey()
        );
    }

    public function test_missing_meta_time_gives_null_occurred_at(): void
    {
        $body = MemberActivityPayloads::courseStarted();
        unset($body['payload'][0]['meta']);

        $this->assertNull(WebhookParser::parse($body)->occurredAt());
    }

    public function test_known_spec_event_parses_as_generic(): void
    {
        $event = WebhookParser::parse([
            'id' => 'hash_id',
            'event' => 'tag_added',
            'payload' => [
                ['id' => '1', 'type' => 'contacts', 'attributes' => ['email' => 'a@example.com']],
                ['id' => '2', 'type' => 'contact_tags', 'attributes' => ['name' => 'VIP']],
            ],
        ]);

        $this->assertInstanceOf(GenericWebhookEvent::class, $event);
        $this->assertSame(WebhookEvent::TagAdded, $event->eventType());
        $this->assertSame('VIP', $event->delivery->resource('contact_tags')['attributes']['name']);
        $this->assertNull($event->occurredAt());
        $this->assertSame('tag_added:hash_id', $event->contentKey());
    }

    public function test_unknown_event_parses_as_generic(): void
    {
        $event = WebhookParser::parse(['id' => 'x', 'event' => 'something_new', 'payload' => []]);

        $this->assertInstanceOf(GenericWebhookEvent::class, $event);
        $this->assertNull($event->eventType());
        $this->assertSame('something_new', $event->eventName());
    }

    public function test_parse_sample_payload_array(): void
    {
        $payload = MemberActivityPayloads::memberSignedIn()['payload'];

        $event = WebhookParser::parseSample(WebhookEvent::MemberSignedIn, $payload);

        $this->assertInstanceOf(MemberSignedIn::class, $event);
        $this->assertSame('sample', $event->deliveryId());
    }

    public function test_invalid_json_throws(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        $this->expectExceptionMessage('not valid JSON');

        WebhookParser::parse('{not json');
    }

    public function test_json_scalar_throws(): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);

        WebhookParser::parse('"just a string"');
    }

    /**
     * @dataProvider malformedBodies
     */
    public function test_malformed_body_throws(array $body, string $message): void
    {
        $this->expectException(InvalidWebhookPayloadException::class);
        $this->expectExceptionMessage($message);

        WebhookParser::parse($body);
    }

    public static function malformedBodies(): array
    {
        return [
            'missing id' => [['event' => 'lesson_completed', 'payload' => []], 'delivery id'],
            'empty id' => [['id' => '', 'event' => 'lesson_completed', 'payload' => []], 'delivery id'],
            'missing event' => [['id' => 'x', 'payload' => []], 'event name'],
            'missing payload' => [['id' => 'x', 'event' => 'lesson_completed'], 'payload array'],
            'payload not array' => [['id' => 'x', 'event' => 'lesson_completed', 'payload' => 'nope'], 'payload array'],
        ];
    }

    public function test_missing_required_resource_throws(): void
    {
        $body = MemberActivityPayloads::lessonCompleted();
        unset($body['payload'][1]); // lessons

        $this->expectException(InvalidWebhookPayloadException::class);
        $this->expectExceptionMessage('lesson_completed payload has no "lessons" resource');

        WebhookParser::parse($body);
    }

    public function test_delivery_resource_helpers(): void
    {
        $delivery = WebhookDelivery::fromArray([
            'id' => 'd',
            'event' => 'x',
            'payload' => [
                ['id' => '1', 'type' => 'a'],
                ['id' => '2', 'type' => 'b'],
                ['id' => '3', 'type' => 'a'],
                'not-a-resource',
            ],
        ]);

        $this->assertSame('1', $delivery->resource('a')['id']);
        $this->assertNull($delivery->resource('missing'));
        $this->assertSame(['1', '3'], array_column($delivery->resources('a'), 'id'));
    }

    public function test_only_429_is_retried(): void
    {
        $this->assertTrue(WebhookParser::isRetriedStatus(429));
        $this->assertFalse(WebhookParser::isRetriedStatus(500));
        $this->assertFalse(WebhookParser::isRetriedStatus(410));
    }
}
