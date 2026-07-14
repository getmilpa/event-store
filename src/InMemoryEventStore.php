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
 * Array-backed {@see EventStoreInterface}: same append/replay/nextSeq contract as
 * {@see FileEventStore}, kept entirely in process memory. For tests and zero-file consumers —
 * nothing is written to disk and nothing survives past the instance's lifetime.
 */
final class InMemoryEventStore implements EventStoreInterface
{
    /** @var list<Event> */
    private array $events = [];

    /**
     * Appends `$event` to the in-memory log. Events are never mutated or removed once appended.
     */
    public function append(Event $event): void
    {
        $this->events[] = $event;
    }

    /**
     * All events belonging to `$streamId`, in ascending `seq` order.
     *
     * @return list<Event>
     */
    public function replay(string $streamId): array
    {
        $events = array_values(array_filter(
            $this->events,
            static fn (Event $event): bool => $event->streamId === $streamId,
        ));

        usort($events, static fn (Event $a, Event $b): int => $a->seq <=> $b->seq);

        return $events;
    }

    /**
     * The next `seq` to assign, one past the highest `seq` currently in the store (across every
     * stream) — `1` for an empty store.
     */
    public function nextSeq(): int
    {
        $max = 0;
        foreach ($this->events as $event) {
            $max = max($max, $event->seq);
        }

        return $max + 1;
    }

    /**
     * Every distinct stream id present in the store, in the order each first appears (ascending
     * `seq` of its first event).
     *
     * @return list<string>
     */
    public function streams(): array
    {
        $ids = [];
        foreach ($this->events as $event) {
            if (!in_array($event->streamId, $ids, true)) {
                $ids[] = $event->streamId;
            }
        }

        return $ids;
    }
}
