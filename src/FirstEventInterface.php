<?php

/**
 * This file is part of Milpa Event Store — the append-only event-log primitive of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/event-store
 */

declare(strict_types=1);

namespace Milpa\EventStore;

/**
 * A store that answers ONE event of a stream without replaying the stream — the event that opened it.
 *
 * Who opened a stream is written once, in its first event of a kind, and never moves. Asking for it
 * through {@see EventStoreInterface::replay()} builds the whole stream to read one row: an agent session
 * of 293 MB died of memory inside the check that only wanted to know who opened it (greenhouse
 * evidence/1045 §3), before anything could record that it died. A store that implements this reads only
 * as far as that row.
 *
 * Separate from {@see EventStoreInterface} so an implementation outside this package keeps compiling;
 * a caller asks `instanceof` and falls back to scanning `replay()` — the same answer, at the old cost.
 */
interface FirstEventInterface
{
    /**
     * The first event of `$type` appended to `$streamId`, or `null` when the stream holds none. Reads
     * only as far as that event.
     *
     * «First appended» is the log's own order. It is also the lowest `seq` of that type in the stream
     * unless two writers numbered and appended concurrently — a race over a kind written once per
     * stream, like an opening, that no writer of such a kind runs.
     */
    public function first(string $streamId, string $type): ?Event;
}
