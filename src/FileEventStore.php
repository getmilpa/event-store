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
 * Append-only JSONL log of {@see Event}s: one JSON object per line, one line per event, never
 * rewritten or truncated. Zero DB — a flat file is the entire durability story.
 *
 * `nextSeq()` and `replay()` both derive their answer from the file itself rather than from an
 * in-memory counter, on purpose: a fresh `FileEventStore` pointed at the same path — a different
 * process, a different request — must agree with every other instance about both "what happened"
 * and "what comes next", and the file is the only thing every instance shares.
 */
final class FileEventStore implements EventStoreInterface
{
    /**
     * @param string $path path to the JSONL log file; its directory is created on first append if missing
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Appends `$event` as one JSON line, under an exclusive lock so concurrent appenders cannot
     * interleave partial lines.
     */
    public function append(Event $event): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create event store directory: {$dir}");
        }

        $handle = fopen($this->path, 'a');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open event store file: {$this->path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock event store file: {$this->path}");
            }

            $line = json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            fwrite($handle, $line . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * All events belonging to `$streamId`, in ascending `seq` order.
     *
     * @return list<Event>
     */
    public function replay(string $streamId): array
    {
        $events = array_values(array_filter(
            $this->readAll(),
            static fn (Event $event): bool => $event->streamId === $streamId,
        ));

        usort($events, static fn (Event $a, Event $b): int => $a->seq <=> $b->seq);

        return $events;
    }

    /**
     * The next `seq` to assign, one past the highest `seq` currently in the store (across every
     * stream) — `1` for an empty or missing log.
     */
    public function nextSeq(): int
    {
        $max = 0;
        foreach ($this->readAll() as $event) {
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
        foreach ($this->readAll() as $event) {
            if (!in_array($event->streamId, $ids, true)) {
                $ids[] = $event->streamId;
            }
        }

        return $ids;
    }

    /**
     * Every stream replayed in a single pass — see {@see EventStoreInterface::replayAll()}. Reads the
     * file ONCE and buckets by stream, where a `replay()`-per-stream loop would read it once per
     * stream.
     *
     * @return array<string, list<Event>>
     */
    public function replayAll(): array
    {
        $byStream = [];
        foreach ($this->readAll() as $event) {
            $byStream[$event->streamId][] = $event;
        }

        foreach ($byStream as &$events) {
            usort($events, static fn (Event $a, Event $b): int => $a->seq <=> $b->seq);
        }
        unset($events);

        return $byStream;
    }

    /**
     * @return list<Event>
     */
    private function readAll(): array
    {
        return array_map(Event::fromArray(...), $this->readRows());
    }

    /**
     * @return list<array{stream_id: string, type: string, payload: array<string,mixed>, seq: int, recorded_at?: ?string}>
     */
    private function readRows(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open event store file: {$this->path}");
        }

        $rows = [];

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Unable to lock event store file: {$this->path}");
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $rows;
    }
}
