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
 * An append-only log of {@see Event}s, partitioned into independently replayable streams. A stream's
 * state is never stored — it is always reconstructed by replaying its events in order, so an
 * implementation's only jobs are "append durably" and "read back in order".
 */
interface EventStoreInterface
{
    /**
     * Appends `$event` to the store. Events are never mutated or removed once appended.
     */
    public function append(Event $event): void;

    /**
     * All events belonging to `$streamId`, in ascending `seq` order. Events belonging to other
     * streams never appear in the result.
     *
     * @return list<Event>
     */
    public function replay(string $streamId): array;

    /**
     * The next `seq` to assign, one past the highest `seq` currently in the store across every
     * stream — `1` for an empty store. The sequence is a single monotonic counter shared by the
     * whole store, not per stream.
     */
    public function nextSeq(): int;

    /**
     * Every distinct stream id present in the store, in the order each first appears (ascending
     * `seq` of its first event) — `[]` for an empty store. For read-side scans across ALL streams
     * (e.g. discovering every process instance a consumer has ever started) that cannot start from
     * a single, already-known stream id the way {@see self::replay()} does.
     *
     * @return list<string>
     */
    public function streams(): array;

    /**
     * Every stream in the store replayed in a SINGLE pass: a map of stream id to that stream's
     * events in ascending `seq` order, keyed in first-appearance order (the same order as
     * {@see self::streams()}) — `[]` for an empty store.
     *
     * Behaves exactly like calling {@see self::replay()} once per {@see self::streams()} entry, but
     * reads the underlying store ONCE instead of once per stream. That is the whole reason it exists:
     * listing every stream via `replay()` in a loop is O(streams) full scans, which turns
     * quadratic when a consumer reconstructs many streams at once.
     *
     * @return array<string, list<Event>>
     */
    public function replayAll(): array;
}
