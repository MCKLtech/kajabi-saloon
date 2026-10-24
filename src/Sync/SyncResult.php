<?php

namespace WooNinja\KajabiSaloon\Sync;

use Carbon\CarbonImmutable;

/**
 * Outcome of one CourseProgressSync run (or one leg of a resumed run)
 */
final class SyncResult
{
    /**
     * @param int[] $row_ids Progress row ids received in this leg (each once)
     * @param string|null $next_cursor Set when the run stopped early (shouldStop); null once it finished
     * @param bool $resumed This leg continued an earlier one, so $row_ids covers only part of the run
     */
    public function __construct(
        public readonly int              $product_id,
        public readonly CarbonImmutable  $started_at,      // the run's start (kept across resumes); persist as lastSyncStartedAt once complete
        public readonly bool             $full,
        public readonly ?CarbonImmutable $updated_since,   // window start used, null for a full pass
        public readonly array            $row_ids,
        public readonly ?string          $next_cursor = null,
        public readonly bool             $resumed = false,
    )
    {
    }

    public function count(): int
    {
        return count($this->row_ids);
    }

    /**
     * The walk reached the last page. Only then persist started_at.
     */
    public function isComplete(): bool
    {
        return $this->next_cursor === null;
    }

    /**
     * What to store to continue an unfinished run, or null when it completed
     */
    public function checkpoint(): ?SyncCheckpoint
    {
        if ($this->next_cursor === null) {
            return null;
        }

        return new SyncCheckpoint(
            product_id: $this->product_id,
            full: $this->full,
            updated_since: $this->updated_since,
            started_at: $this->started_at,
            after: $this->next_cursor,
        );
    }

    /**
     * Stored row ids for this course that didn't come back - delete them after a full pass
     *
     * Needs a full pass done in one leg. For a full pass split across resumes,
     * stamp each row with $started_at in the upsert, then once the final leg
     * completes delete the course's rows stamped earlier.
     *
     * @param iterable<int|string> $storedRowIds
     * @return int[]
     * @throws \LogicException on an incremental, unfinished or resumed result, which holds only some rows
     */
    public function missingFrom(iterable $storedRowIds): array
    {
        if (!$this->full) {
            throw new \LogicException('Only a full pass can tell which stored rows are gone');
        }

        if (!$this->isComplete() || $this->resumed) {
            throw new \LogicException(
                'This full pass was split across runs, so its row ids are partial; delete rows not stamped with started_at instead'
            );
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
