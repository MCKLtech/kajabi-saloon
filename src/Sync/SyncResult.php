<?php

namespace WooNinja\KajabiSaloon\Sync;

use Carbon\CarbonImmutable;

/**
 * Outcome of one CourseProgressSync run
 */
final class SyncResult
{
    /**
     * @param int[] $row_ids Progress row ids received (each once)
     */
    public function __construct(
        public readonly int              $product_id,
        public readonly CarbonImmutable  $started_at,      // persist as the next run's lastSyncStartedAt
        public readonly bool             $full,
        public readonly ?CarbonImmutable $updated_since,   // window start used, null for a full pass
        public readonly array            $row_ids,
    )
    {
    }

    public function count(): int
    {
        return count($this->row_ids);
    }

    /**
     * Stored row ids for this course that didn't come back - delete them after a full pass
     *
     * @param iterable<int|string> $storedRowIds
     * @return int[]
     * @throws \LogicException on an incremental result, which only holds changed rows
     */
    public function missingFrom(iterable $storedRowIds): array
    {
        if (!$this->full) {
            throw new \LogicException('Only a full pass can tell which stored rows are gone');
        }

        $seen = array_flip($this->row_ids);
        $missing = [];

        foreach ($storedRowIds as $id) {
            if (!isset($seen[(int) $id])) {
                $missing[] = (int) $id;
            }
        }

        return $missing;
    }
}
