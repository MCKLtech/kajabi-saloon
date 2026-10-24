<?php

namespace WooNinja\KajabiSaloon\Sync;

use Carbon\CarbonImmutable;
use WooNinja\KajabiSaloon\DataTransferObjects\CourseProgress\CourseProgress;
use WooNinja\KajabiSaloon\Requests\CourseProgress\GetCourseProgress;
use WooNinja\KajabiSaloon\Services\CourseProgressService;

/**
 * Kajabi's recommended course progress sync, per course:
 *
 * 1. incremental() often: rows updated since the previous run's START minus a
 *    margin (not the newest updated_at seen - a long walk would skip rows).
 * 2. full() periodically (e.g. nightly): every row; then delete stored rows
 *    that didn't come back (merges, deleted members, publish/unpublish changes
 *    never show up in updated_since).
 *
 * Storage stays with the caller: pass an upsert callback keyed on $row->id, and
 * persist SyncResult::$started_at only after the run completes. If a run throws,
 * don't advance it - the next run repeats the same window.
 *
 * Long walks can be parked: pass $shouldStop (e.g. a deadline check), store
 * $result->checkpoint() when !$result->isComplete(), and resume() it later.
 *
 * ```php
 * $result = $kajabi->courseProgress->sync()->incremental($courseId, $lastStartedAt, fn($row) => $repo->upsert($row));
 * $lastStartedAt = $result->started_at;
 *
 * $result = $kajabi->courseProgress->sync()->full($courseId, fn($row) => $repo->upsert($row));
 * $repo->deleteIds($result->missingFrom($repo->idsForCourse($courseId)));
 *
 * // With a time budget
 * $deadline = time() + 50;
 * $result = $checkpoint
 *     ? $sync->resume($checkpoint, $upsert, fn() => time() >= $deadline)
 *     : $sync->incremental($courseId, $lastStartedAt, $upsert, fn() => time() >= $deadline);
 * $result->isComplete() ? $lastStartedAt = $result->started_at : $checkpoint = $result->checkpoint();
 * ```
 */
final class CourseProgressSync
{
    public const DEFAULT_MARGIN_SECONDS = 300;

    public function __construct(
        private CourseProgressService $service,
        private int $marginSeconds = self::DEFAULT_MARGIN_SECONDS,
    ) {
        if ($marginSeconds < 0) {
            throw new \InvalidArgumentException('The sync margin must not be negative');
        }
    }

    /**
     * Rows changed since the previous run; a null $lastSyncStartedAt runs a full walk
     *
     * @param callable(CourseProgress): void $upsert
     * @param (callable(): bool)|null $shouldStop Asked before each page after the first;
     *        return true to park the run (see SyncResult::checkpoint())
     */
    public function incremental(
        int $productId,
        ?\DateTimeInterface $lastSyncStartedAt,
        callable $upsert,
        ?callable $shouldStop = null
    ): SyncResult
    {
        if ($lastSyncStartedAt === null) {
            return $this->full($productId, $upsert, $shouldStop);
        }

        $startedAt = CarbonImmutable::now('UTC');
        $since = $this->windowStart($lastSyncStartedAt);

        return $this->walk($productId, $upsert, $startedAt, $since, null, $shouldStop);
    }

    /**
     * Every row for the course
     *
     * @param callable(CourseProgress): void $upsert
     * @param (callable(): bool)|null $shouldStop Asked before each page after the first
     */
    public function full(int $productId, callable $upsert, ?callable $shouldStop = null): SyncResult
    {
        return $this->walk($productId, $upsert, CarbonImmutable::now('UTC'), null, null, $shouldStop);
    }

    /**
     * Continue a parked run from its checkpoint, with its original window and start time
     *
     * @param callable(CourseProgress): void $upsert
     * @param (callable(): bool)|null $shouldStop Asked before each page after the first
     */
    public function resume(SyncCheckpoint $checkpoint, callable $upsert, ?callable $shouldStop = null): SyncResult
    {
        return $this->walk(
            $checkpoint->product_id,
            $upsert,
            $checkpoint->started_at,
            $checkpoint->full ? null : $checkpoint->updated_since,
            $checkpoint->after,
            $shouldStop
        );
    }

    /**
     * Start of the incremental window: previous run's start minus the margin, in UTC
     */
    public function windowStart(\DateTimeInterface $lastSyncStartedAt): CarbonImmutable
    {
        return CarbonImmutable::instance($lastSyncStartedAt)
            ->utc()
            ->subSeconds($this->marginSeconds);
    }

    private function walk(
        int $productId,
        callable $upsert,
        CarbonImmutable $startedAt,
        ?CarbonImmutable $since,
        ?string $after,
        ?callable $shouldStop
    ): SyncResult
    {
        $filters = ['limit' => GetCourseProgress::MAX_PAGE_SIZE];

        if ($since !== null) {
            $filters['updated_since'] = $since;
        }

        $resumed = $after !== null;
        $rowIds = [];
        $first = true;

        do {
            // Always fetch one page, so every call makes progress
            if (!$first && $shouldStop !== null && $shouldStop()) {
                break;
            }

            $page = $this->service->page($productId, $filters, $after);

            foreach ($page->rows as $row) {
                $upsert($row);
                $rowIds[$row->id] = true;
            }

            $after = $page->nextCursor;
            $first = false;
        } while ($after !== null);

        return new SyncResult(
            product_id: $productId,
            started_at: $startedAt,
            full: $since === null,
            updated_since: $since,
            row_ids: array_keys($rowIds),
            next_cursor: $after,
            resumed: $resumed,
        );
    }
}
