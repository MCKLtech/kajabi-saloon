<?php

namespace WooNinja\KajabiSaloon\Sync;

use Carbon\CarbonImmutable;

/**
 * Where a parked CourseProgressSync run stopped, so a later process can carry on
 *
 * Holds the original run's window and start time, not just the cursor: a
 * resumed incremental run must keep the same updated_since, and the run's
 * started_at is what to persist once it finally completes.
 *
 * ```php
 * $store->put($key, json_encode($result->checkpoint()->toArray()));
 * // next job
 * $result = $sync->resume(SyncCheckpoint::fromArray(json_decode($store->get($key), true)), $upsert, $shouldStop);
 * ```
 */
final class SyncCheckpoint
{
    /**
     * ISO 8601 with microseconds, so a resumed run's started_at is exactly the original
     */
    private const TIMESTAMP_FORMAT = 'Y-m-d\\TH:i:s.uP';

    public function __construct(
        public readonly int              $product_id,
        public readonly bool             $full,
        public readonly ?CarbonImmutable $updated_since,
        public readonly CarbonImmutable  $started_at,
        public readonly string           $after,
    )
    {
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->product_id,
            'full' => $this->full,
            'updated_since' => $this->updated_since?->format(self::TIMESTAMP_FORMAT),
            'started_at' => $this->started_at->format(self::TIMESTAMP_FORMAT),
            'after' => $this->after,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            product_id: (int) $data['product_id'],
            full: (bool) $data['full'],
            updated_since: isset($data['updated_since']) ? CarbonImmutable::parse($data['updated_since'])->utc() : null,
            started_at: CarbonImmutable::parse($data['started_at'])->utc(),
            after: (string) $data['after'],
        );
    }
}
