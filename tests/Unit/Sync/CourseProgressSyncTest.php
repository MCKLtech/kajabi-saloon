<?php

namespace Tests\Unit\Sync;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fixtures\MemberActivityPayloads;
use WooNinja\KajabiSaloon\Connectors\KajabiConnector;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\Services\CourseProgressService;
use WooNinja\KajabiSaloon\Services\KajabiService;
use WooNinja\KajabiSaloon\Sync\CourseProgressSync;
use WooNinja\KajabiSaloon\Sync\SyncCheckpoint;
use WooNinja\KajabiSaloon\Sync\SyncResult;

class CourseProgressSyncTest extends TestCase
{
    protected function setUp(): void
    {
        CarbonImmutable::setTestNow('2026-10-20T15:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }

    private function sync(MockClient $mockClient, int $margin = CourseProgressSync::DEFAULT_MARGIN_SECONDS): CourseProgressSync
    {
        $kajabi = new KajabiService('test-client-id', 'test-client-secret', '111111');
        $kajabi->setConnector((new KajabiConnector())->withMockClient($mockClient));

        return new CourseProgressSync(new CourseProgressService($kajabi), $margin);
    }

    private function twoPages(): MockClient
    {
        return new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage(
                [MemberActivityPayloads::progressRowComplete(), MemberActivityPayloads::progressRowPartial()],
                nextAfter: '900002'
            )),
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowEmpty()])),
        ]);
    }

    public function test_window_starts_at_previous_start_minus_margin_in_utc(): void
    {
        $sync = $this->sync(new MockClient([]));

        $this->assertSame(
            '2026-10-20T13:55:00+00:00',
            $sync->windowStart(new \DateTimeImmutable('2026-10-20T14:00:00Z'))->toAtomString()
        );
        $this->assertSame(
            '2026-10-20T13:55:00+00:00',
            $sync->windowStart(new \DateTimeImmutable('2026-10-20T09:00:00', new \DateTimeZone('America/Chicago')))->toAtomString()
        );
    }

    public function test_incremental_walks_window_and_upserts_every_row(): void
    {
        $mockClient = $this->twoPages();
        $upserted = [];

        $result = $this->sync($mockClient)->incremental(
            2000001,
            new \DateTimeImmutable('2026-10-20T14:00:00Z'),
            function (CourseProgress $row) use (&$upserted) {
                $upserted[] = $row->id;
            }
        );

        $this->assertSame([900001, 900002, 900003], $upserted);
        $this->assertInstanceOf(SyncResult::class, $result);
        $this->assertFalse($result->full);
        $this->assertSame(2000001, $result->product_id);
        $this->assertSame(3, $result->count());
        $this->assertSame([900001, 900002, 900003], $result->row_ids);
        $this->assertSame('2026-10-20T15:00:00+00:00', $result->started_at->toAtomString());
        $this->assertSame('2026-10-20T13:55:00+00:00', $result->updated_since->toAtomString());

        $recorded = $mockClient->getRecordedResponses();
        $this->assertSame(100, (int) $recorded[0]->getPendingRequest()->query()->get('page[size]'));

        foreach ($recorded as $response) {
            $query = $response->getPendingRequest()->query()->all();
            $this->assertSame('2026-10-20T13:55:00+00:00', $query['filter[updated_since]']);
        }
    }

    public function test_started_at_is_taken_before_the_walk(): void
    {
        $mockClient = new MockClient([
            function () {
                // Time moves on while the walk runs
                CarbonImmutable::setTestNow('2026-10-20T15:30:00Z');

                return MockResponse::make(MemberActivityPayloads::progressPage([]));
            },
        ]);

        $result = $this->sync($mockClient)->incremental(1, new \DateTimeImmutable('2026-10-20T14:00:00Z'), fn() => null);

        $this->assertSame('2026-10-20T15:00:00+00:00', $result->started_at->toAtomString());
    }

    public function test_incremental_without_previous_run_does_full_pass(): void
    {
        $mockClient = $this->twoPages();

        $result = $this->sync($mockClient)->incremental(2000001, null, fn() => null);

        $this->assertTrue($result->full);
        $this->assertNull($result->updated_since);
        $this->assertArrayNotHasKey('filter[updated_since]', $mockClient->getLastPendingRequest()->query()->all());
    }

    public function test_full_pass_reports_missing_stored_rows(): void
    {
        $mockClient = $this->twoPages();

        $result = $this->sync($mockClient)->full(2000001, fn() => null);

        $this->assertTrue($result->full);
        foreach ($mockClient->getRecordedResponses() as $recorded) {
            $this->assertArrayNotHasKey('filter[updated_since]', $recorded->getPendingRequest()->query()->all());
        }

        $this->assertSame([111, 222], $result->missingFrom([900001, '111', 900003, 222, '900002']));
        $this->assertSame([], $result->missingFrom([]));
    }

    public function test_duplicate_rows_are_counted_once(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowComplete()], nextAfter: '900001')),
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowComplete()])),
        ]);

        $calls = 0;
        $result = $this->sync($mockClient)->full(1, function () use (&$calls) {
            $calls++;
        });

        $this->assertSame(2, $calls, 'upsert is idempotent by id, so it may see a row twice');
        $this->assertSame([900001], $result->row_ids);
    }

    public function test_missing_from_is_only_valid_after_full_pass(): void
    {
        $result = $this->sync(new MockClient([MockResponse::make(MemberActivityPayloads::progressPage([]))]))
            ->incremental(1, new \DateTimeImmutable('2026-10-20T14:00:00Z'), fn() => null);

        $this->expectException(\LogicException::class);
        $result->missingFrom([1]);
    }

    public function test_failure_mid_walk_propagates_so_caller_does_not_advance(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowComplete()], nextAfter: '900001')),
            MockResponse::make(['errors' => []], 500),
        ]);

        $upserted = 0;

        try {
            $this->sync($mockClient)->incremental(1, new \DateTimeImmutable('2026-10-20T14:00:00Z'), function () use (&$upserted) {
                $upserted++;
            });
            $this->fail('Expected the failure to propagate');
        } catch (RequestException) {
        }

        $this->assertSame(1, $upserted, 'rows before the failure were still upserted');
    }

    public function test_custom_margin(): void
    {
        $this->assertSame(
            '2026-10-20T14:00:00+00:00',
            $this->sync(new MockClient([]), 0)->windowStart(new \DateTimeImmutable('2026-10-20T14:00:00Z'))->toAtomString()
        );
    }

    public function test_negative_margin_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sync(new MockClient([]), -1);
    }

    private function threePages(): MockClient
    {
        return new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowComplete()], nextAfter: '900001')),
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowPartial()], nextAfter: '900002')),
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowEmpty()])),
        ]);
    }

    public function test_should_stop_parks_the_run_after_a_page(): void
    {
        $mockClient = $this->threePages();
        $asked = 0;

        $result = $this->sync($mockClient)->incremental(
            2000001,
            new \DateTimeImmutable('2026-10-20T14:00:00Z'),
            fn() => null,
            function () use (&$asked) {
                $asked++;

                return true;
            }
        );

        $mockClient->assertSentCount(1);
        $this->assertSame(1, $asked, 'not asked before the first page');
        $this->assertFalse($result->isComplete());
        $this->assertSame('900001', $result->next_cursor);
        $this->assertSame([900001], $result->row_ids);

        $checkpoint = $result->checkpoint();
        $this->assertInstanceOf(SyncCheckpoint::class, $checkpoint);
        $this->assertSame('900001', $checkpoint->after);
        $this->assertFalse($checkpoint->full);
        $this->assertSame('2026-10-20T13:55:00+00:00', $checkpoint->updated_since->toAtomString());
        $this->assertSame('2026-10-20T15:00:00+00:00', $checkpoint->started_at->toAtomString());
    }

    public function test_should_stop_false_walks_everything(): void
    {
        $mockClient = $this->threePages();

        $result = $this->sync($mockClient)->full(2000001, fn() => null, fn() => false);

        $mockClient->assertSentCount(3);
        $this->assertTrue($result->isComplete());
        $this->assertNull($result->checkpoint());
        $this->assertSame([900001, 900002, 900003], $result->row_ids);
    }

    public function test_resume_keeps_window_and_original_start(): void
    {
        $parked = $this->sync($this->threePages())->incremental(
            2000001,
            new \DateTimeImmutable('2026-10-20T14:00:00Z'),
            fn() => null,
            fn() => true
        );

        // A later job, an hour on, from the stored checkpoint
        CarbonImmutable::setTestNow('2026-10-20T16:00:00Z');
        $stored = json_decode(json_encode($parked->checkpoint()->toArray()), true);

        $mockClient = new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowPartial()], nextAfter: '900002')),
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowEmpty()])),
        ]);
        $upserted = [];

        $result = $this->sync($mockClient)->resume(
            SyncCheckpoint::fromArray($stored),
            function (CourseProgress $row) use (&$upserted) {
                $upserted[] = $row->id;
            }
        );

        $this->assertSame([900002, 900003], $upserted);
        $this->assertTrue($result->isComplete());
        $this->assertTrue($result->resumed);
        $this->assertFalse($result->full);
        $this->assertSame('2026-10-20T15:00:00+00:00', $result->started_at->toAtomString(), 'the original start, not now');

        $queries = array_map(fn($r) => $r->getPendingRequest()->query()->all(), $mockClient->getRecordedResponses());
        $this->assertSame('900001', $queries[0]['page[after]']);
        $this->assertSame('900002', $queries[1]['page[after]']);
        foreach ($queries as $query) {
            $this->assertSame('2026-10-20T13:55:00+00:00', $query['filter[updated_since]'], 'same window as the original run');
        }
    }

    public function test_resumed_full_pass_cannot_report_missing_rows(): void
    {
        $parked = $this->sync($this->threePages())->full(2000001, fn() => null, fn() => true);

        try {
            $parked->missingFrom([1]);
            $this->fail('An unfinished full pass must not report deletions');
        } catch (\LogicException) {
        }

        $mockClient = new MockClient([
            MockResponse::make(MemberActivityPayloads::progressPage([MemberActivityPayloads::progressRowEmpty()])),
        ]);
        $finished = $this->sync($mockClient)->resume($parked->checkpoint(), fn() => null);

        $this->assertTrue($finished->full);
        $this->assertTrue($finished->isComplete());
        $this->assertArrayNotHasKey('filter[updated_since]', $mockClient->getLastPendingRequest()->query()->all());

        $this->expectException(\LogicException::class);
        $finished->missingFrom([900001]);
    }

    public function test_checkpoint_round_trips(): void
    {
        $checkpoint = new SyncCheckpoint(
            product_id: 7,
            full: true,
            updated_since: null,
            started_at: CarbonImmutable::parse('2026-10-20T09:00:00.123456-05:00'),
            after: '123',
        );

        $restored = SyncCheckpoint::fromArray($checkpoint->toArray());

        $this->assertSame(7, $restored->product_id);
        $this->assertTrue($restored->full);
        $this->assertNull($restored->updated_since);
        $this->assertSame('2026-10-20T14:00:00.123456+00:00', $restored->started_at->format('Y-m-d\\TH:i:s.uP'), 'microseconds kept');
        $this->assertSame('123', $restored->after);
    }
}
