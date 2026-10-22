<?php

namespace Tests\Unit\Services;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\MemberActivityPayloads;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\LessonProgress;
use WooNinja\KajabiSaloon\Exceptions\FeatureNotEnabledException;
use WooNinja\KajabiSaloon\Requests\CourseProgress\GetCourseProgress;
use WooNinja\KajabiSaloon\Services\CourseProgressService;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\KajabiSaloon\Sync\CourseProgressSync;

class CourseProgressServiceTest extends TestCase
{
    private function serviceWithMock(MockClient $mockClient): CourseProgressService
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', '111111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));

        return new CourseProgressService($kajabi);
    }

    private function sendAndGetQuery(GetCourseProgress $request): array
    {
        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        (new KajabiConnector())->withMockClient($mockClient)->send($request);

        return $mockClient->getLastPendingRequest()->query()->all();
    }

    /**
     * @return CourseProgress[]
     */
    private function collect(\Saloon\PaginationPlugin\Paginator $paginator): array
    {
        return iterator_to_array($paginator->items(), false);
    }

    public function test_dto_maps_row(): void
    {
        $row = CourseProgress::fromKajabiCourseProgress(MemberActivityPayloads::progressRowPartial(), 2000001);

        $this->assertSame(900002, $row->id);
        $this->assertSame(5000002, $row->customer_id);
        $this->assertSame(2000001, $row->product_id);
        $this->assertSame(1, $row->completed_lessons);
        $this->assertSame(2, $row->total_lessons);
        $this->assertSame(50, $row->percent_complete);
        $this->assertFalse($row->completed);
        $this->assertTrue(Carbon::parse('2026-10-20T19:41:07.310Z')->eq($row->updated_at));

        $this->assertCount(2, $row->lessons);
        $this->assertContainsOnlyInstancesOf(LessonProgress::class, $row->lessons);
        $this->assertSame(3000001, $row->lessons[0]->lesson_id);
        $this->assertTrue($row->lessons[0]->isCompleted());
        $this->assertSame(100, $row->lessons[0]->progress_percent);
        $this->assertFalse($row->lessons[1]->isCompleted());
        $this->assertNull($row->lessons[1]->completed_at);
        $this->assertSame(40, $row->lessons[1]->progress_percent);
    }

    public function test_dto_started_and_completed_at(): void
    {
        $complete = CourseProgress::fromKajabiCourseProgress(MemberActivityPayloads::progressRowComplete());
        $this->assertNull($complete->product_id);
        $this->assertTrue(Carbon::parse('2026-10-20T19:04:11Z')->eq($complete->startedAt()));
        $this->assertTrue(Carbon::parse('2026-10-20T19:20:31Z')->eq($complete->completedAt()));
        $this->assertNull($complete->lessons[1]->progress_percent);

        $partial = CourseProgress::fromKajabiCourseProgress(MemberActivityPayloads::progressRowPartial());
        $this->assertTrue(Carbon::parse('2026-10-20T19:41:07Z')->eq($partial->startedAt()));
        $this->assertNull($partial->completedAt(), 'not complete, so no completion time');

        $empty = CourseProgress::fromKajabiCourseProgress(MemberActivityPayloads::progressRowEmpty());
        $this->assertNull($empty->startedAt());
        $this->assertNull($empty->completedAt());
    }

    public function test_query_translation(): void
    {
        $query = $this->sendAndGetQuery(new GetCourseProgress(2000001, [
            'customer_id' => 5000001,
            'updated_since' => '2026-10-20T14:55:00Z',
            'limit' => 50,
            'after' => 900002,
        ]));

        $this->assertSame([
            'filter[product_id]' => 2000001,
            'filter[customer_id]' => 5000001,
            'filter[updated_since]' => '2026-10-20T14:55:00Z',
            'page[size]' => 50,
            'page[after]' => 900002,
        ], $query);
    }

    public function test_query_accepts_kajabi_style_keys_and_user_id(): void
    {
        $query = $this->sendAndGetQuery(new GetCourseProgress(2000001, [
            'user_id' => 5000001,
            'page[size]' => 10,
            'page[after]' => 5,
            'filter[updated_since]' => '2026-10-20T09:55:00-05:00',
        ]));

        $this->assertSame(5000001, $query['filter[customer_id]']);
        $this->assertSame(10, $query['page[size]']);
        $this->assertSame(5, $query['page[after]']);
        $this->assertSame('2026-10-20T09:55:00-05:00', $query['filter[updated_since]']);
    }

    public function test_page_size_is_capped_at_100(): void
    {
        $query = $this->sendAndGetQuery(new GetCourseProgress(1, ['limit' => 500]));

        $this->assertSame(100, $query['page[size]']);
    }

    public function test_page_number_and_unsupported_params_are_dropped(): void
    {
        $query = $this->sendAndGetQuery(new GetCourseProgress(1, [
            'page' => 2,
            'page[number]' => 2,
            'start_page' => 2,
            'max_pages' => 3,
            'sort' => 'id',
            'query[email]' => 'x@example.com',
        ]));

        $this->assertSame(['filter[product_id]' => 1], $query);
    }

    public function test_datetime_updated_since_keeps_offset(): void
    {
        $utc = new \DateTimeImmutable('2026-10-20T13:55:00', new \DateTimeZone('UTC'));
        $chicago = new \DateTimeImmutable('2026-10-20T09:55:00', new \DateTimeZone('America/Chicago'));

        $this->assertSame('2026-10-20T13:55:00+00:00', GetCourseProgress::formatTimestamp($utc));
        $this->assertSame('2026-10-20T09:55:00-05:00', GetCourseProgress::formatTimestamp($chicago));
    }

    public function test_for_course_follows_cursor_across_pages(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage(
                [MemberActivityPayloads::progressRowComplete(), MemberActivityPayloads::progressRowPartial()],
                nextAfter: '900002'
            )),
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowEmpty()])),
        ]);

        $rows = $this->collect($this->serviceWithMock($mockClient)->forCourse(2000001, ['limit' => 2]));

        $this->assertSame([900001, 900002, 900003], array_map(fn(CourseProgress $r) => $r->id, $rows));
        $this->assertSame(2000001, $rows[2]->product_id);

        $mockClient->assertSentCount(2);
        $requests = array_map(fn($r) => $r->getPendingRequest()->query()->all(), $mockClient->getRecordedResponses());

        $this->assertArrayNotHasKey('page[after]', $requests[0]);
        $this->assertSame('900002', $requests[1]['page[after]']);
        $this->assertSame(2000001, (int) $requests[1]['filter[product_id]']);

        foreach ($requests as $query) {
            $this->assertArrayNotHasKey('page[number]', $query, 'page[number] returns 400');
            $this->assertArrayNotHasKey('page', $query, 'cursor params must not be nested/duplicated');
        }
    }

    public function test_for_course_with_start_page_never_sends_page_number(): void
    {
        $mockClient = new MockClient([MockResponse::make(MemberActivityPayloads::progressPage([]))]);

        $this->collect($this->serviceWithMock($mockClient)->forCourse(2000001, ['page' => 3, 'start_page' => 3]));

        $this->assertArrayNotHasKey('page[number]', $mockClient->getLastPendingRequest()->query()->all());
    }

    public function test_updated_since(): void
    {
        $mockClient = new MockClient([MockResponse::make(MemberActivityPayloads::progressPage([]))]);

        $this->collect($this->serviceWithMock($mockClient)->updatedSince(
            2000001,
            new \DateTimeImmutable('2026-10-20T13:55:00Z'),
            ['customer_id' => 1]
        ));

        $query = $mockClient->getLastPendingRequest()->query()->all();
        $this->assertSame('2026-10-20T13:55:00+00:00', $query['filter[updated_since]']);
        $this->assertSame(1, $query['filter[customer_id]']);
    }

    public function test_for_customer_returns_row(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowComplete()])),
        ]);

        $row = $this->serviceWithMock($mockClient)->forCustomer(2000001, 5000001);

        $this->assertInstanceOf(CourseProgress::class, $row);
        $this->assertSame(5000001, $row->customer_id);

        $query = $mockClient->getLastPendingRequest()->query()->all();
        $this->assertSame(5000001, $query['filter[customer_id]']);
        $this->assertSame(1, $query['page[size]']);
    }

    public function test_for_customer_returns_null_without_progress_record(): void
    {
        $mockClient = new MockClient([MockResponse::make(MemberActivityPayloads::progressPage([]))]);

        $this->assertNull($this->serviceWithMock($mockClient)->forCustomer(2000001, 5000001));
    }

    public function test_423_throws_feature_not_enabled(): void
    {
        $mockClient = new MockClient([MockResponse::make(['errors' => [['status' => '423']]], 423)]);

        try {
            $this->serviceWithMock($mockClient)->forCustomer(2000001, 5000001);
            $this->fail('Expected FeatureNotEnabledException');
        } catch (FeatureNotEnabledException $e) {
            $this->assertInstanceOf(RequestException::class, $e, 'existing RequestException handlers still catch it');
            $this->assertSame(423, $e->getResponse()->status());
            $this->assertStringContainsString('not available for this account', $e->getMessage());
        }
    }

    public function test_423_throws_feature_not_enabled_while_paginating(): void
    {
        $mockClient = new MockClient([MockResponse::make([], 423)]);

        $this->expectException(FeatureNotEnabledException::class);

        $this->collect($this->serviceWithMock($mockClient)->forCourse(2000001));
    }

    public function test_other_errors_are_regular_request_exceptions(): void
    {
        $mockClient = new MockClient([MockResponse::make(['errors' => []], 404)]);

        try {
            $this->serviceWithMock($mockClient)->forCustomer(999, 1);
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertNotInstanceOf(FeatureNotEnabledException::class, $e);
            $this->assertSame(404, $e->getResponse()->status());
        }
    }

    public function test_sync_factory(): void
    {
        $service = $this->serviceWithMock(new MockClient([]));

        $this->assertInstanceOf(CourseProgressSync::class, $service->sync());
        $this->assertSame(
            '2026-10-20T13:59:00+00:00',
            $service->sync(60)->windowStart(new \DateTimeImmutable('2026-10-20T14:00:00Z'))->toAtomString()
        );
    }
}
