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
 * persist SyncResult::$started_at only after the run returns. If a run throws,
 * don't advance it - the next run repeats the same window.
 *
 * ```php
 * $result = $kajabi->courseProgress->sync()->incremental($courseId, $lastStartedAt, fn($row) => $repo->upsert($row));
 * $lastStartedAt = $result->started_at;
 *
 * $result = $kajabi->courseProgress->sync()->full($courseId, fn($row) => $repo->upsert($row));
 * $repo->deleteIds($result->missingFrom($repo->idsForCourse($courseId)));
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
     */
    public function incremental(int $productId, ?\DateTimeInterface $lastSyncStartedAt, callable $upsert): SyncResult
    {
        if ($lastSyncStartedAt === null) {
            return $this->full($productId, $upsert);
        }

        $startedAt = CarbonImmutable::now('UTC');
        $since = $this->windowStart($lastSyncStartedAt);

        return $this->walk($productId, ['updated_since' => $since], $upsert, $startedAt, $since);
    }

    /**
     * Every row for the course
     *
     * @param callable(CourseProgress): void $upsert
     */
    public function full(int $productId, callable $upsert): SyncResult
    {
        return $this->walk($productId, [], $upsert, CarbonImmutable::now('UTC'), null);
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
        array $filters,
        callable $upsert,
        CarbonImmutable $startedAt,
        ?CarbonImmutable $since
    ): SyncResult
    {
        $rowIds = [];
        $rows = $this->service->forCourse(
            $productId,
            $filters + ['limit' => GetCourseProgress::MAX_PAGE_SIZE]
        );

        foreach ($rows->items() as $row) {
            $upsert($row);
            $rowIds[$row->id] = true;
        }

        return new SyncResult(
            product_id: $productId,
            started_at: $startedAt,
            full: $since === null,
            updated_since: $since,
            row_ids: array_keys($rowIds),
        );
    }
}
