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
 * and "what comes next", and the file is the only thing every instance shares. An instance does
 * remember where its last read ended and the last stream it replayed, but only to ask the file for
 * what was appended since: every call checks that it is still the same file, no shorter, with the
 * same last line, and reads it again from the start when it is not.
 */
final class FileEventStore implements EventStoreInterface
{
    /**
     * Where the last read of the log ended, and the file it read — `null` before any read or once
     * the file stopped being the one read.
     *
     * @var array{dev: int, ino: int, offset: int, maxSeq: int, lastLength: int, lastHash: string}|null
     */
    private ?array $position = null;

    /**
     * The last stream replayed, complete up to {@see self::$position}.
     *
     * @var array{stream: string, events: list<Event>}|null
     */
    private ?array $kept = null;

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
     * The last stream asked for is kept, and every later call asks the FILE what changed since:
     * the same file (device and inode), not shorter, and the last line read still the same bytes.
     * If all three hold, only the lines appended since are read, and the kept stream grows by the
     * ones that are its own; if any fails, the log is read again from the start. A long agent leg
     * asked for its own 21 MB session 28 times and held four decoded copies of it at once, ~26 MB
     * each (greenhouse evidence/1045): one shared read is the difference between fitting in PHP's
     * 128 MB and dying there.
     *
     * The same list is handed to every caller — {@see Event} is immutable, so sharing it is safe.
     *
     * @return list<Event>
     */
    public function replay(string $streamId): array
    {
        if ($this->kept !== null && $this->kept['stream'] === $streamId && $this->catchUp()) {
            return $this->kept['events'];
        }

        $events = [];
        $this->readFromStart(static function (array $row) use ($streamId, &$events): void {
            // Only the stream's own rows become events: the log holds every stream of the house, and building
            // all of them to keep one cost a 21 MB ledger ~26 MB per read (greenhouse evidence/1042).
            if (($row['stream_id'] ?? null) === $streamId) {
                $events[] = Event::fromArray($row);
            }
        });
        usort($events, static fn (Event $a, Event $b): int => $a->seq <=> $b->seq);

        $this->kept = $this->position === null ? null : ['stream' => $streamId, 'events' => $events];

        return $events;
    }

    /**
     * The next `seq` to assign, one past the highest `seq` currently in the store (across every
     * stream) — `1` for an empty or missing log.
     */
    public function nextSeq(): int
    {
        // One line at a time, and only the lines appended since the last read: numbering the next event needs
        // the highest `seq`, not the whole log in memory (greenhouse evidence/1042) nor read again (1045).
        if (!$this->catchUp()) {
            $this->readFromStart(static function (array $row): void {
            });
        }

        return ($this->position['maxSeq'] ?? 0) + 1;
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
     * Visit every row of the log from its first byte, decoded one line at a time under a shared
     * lock — the same rows {@see self::readRows()} would return, without holding them all at once —
     * and remember where the read ended, so the next call can start there.
     *
     * The kept stream is dropped: whatever the caller keeps next was read by this pass.
     *
     * @param callable(array<string,mixed>): void $visit
     */
    private function readFromStart(callable $visit): void
    {
        $this->position = null;
        $this->kept = null;

        if (!is_file($this->path)) {
            return;
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open event store file: {$this->path}");
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Unable to lock event store file: {$this->path}");
            }

            $this->position = $this->readLines($handle, $this->identity($handle), $visit);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Bring the remembered read up to the end of the log, reading only what was appended since.
     * `false` when there is nothing to trust — no read yet, a missing file, another file at the same
     * path, or a last line that no longer reads the same (a shorter file included) — and the caller
     * must read from the start.
     */
    private function catchUp(): bool
    {
        $position = $this->position;
        if ($position === null || !is_file($this->path)) {
            return false;
        }

        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open event store file: {$this->path}");
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Unable to lock event store file: {$this->path}");
            }

            $stat = fstat($handle);
            if ($stat === false || $stat['dev'] !== $position['dev'] || $stat['ino'] !== $position['ino']) {
                return $this->forget();
            }

            // The last line read, read again: a shorter file answers fewer bytes, a rewritten one other bytes.
            if ($position['lastLength'] > 0) {
                fseek($handle, $position['offset'] - $position['lastLength']);
                if (hash('xxh128', (string) fread($handle, $position['lastLength'])) !== $position['lastHash']) {
                    return $this->forget();
                }
            }

            if ($stat['size'] === $position['offset']) {
                return true;
            }

            fseek($handle, $position['offset']);
            $appended = [];
            $stream = $this->kept['stream'] ?? null;
            $this->position = $this->readLines($handle, $position, static function (array $row) use ($stream, &$appended): void {
                if ($stream !== null && ($row['stream_id'] ?? null) === $stream) {
                    $appended[] = Event::fromArray($row);
                }
            });
            if ($this->position === null) {
                return $this->forget();
            }

            if ($this->kept !== null && $appended !== []) {
                $events = $this->kept['events'];
                $last = $events === [] ? null : $events[\count($events) - 1]->seq;
                $ordered = true;
                foreach ($appended as $event) {
                    $ordered = $ordered && ($last === null || $event->seq >= $last);
                    $last = $event->seq;
                    $events[] = $event;
                }
                if (!$ordered) {
                    usort($events, static fn (Event $a, Event $b): int => $a->seq <=> $b->seq);
                }
                $this->kept['events'] = $events;
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Read and decode every line from the handle's current offset to the end, visiting each row.
     * Answers where the read ended, or `null` when the log ends in a line with no newline — a write
     * that never finished, which no later read may start after.
     *
     * @param resource                                                                               $handle
     * @param array{dev: int, ino: int, offset: int, maxSeq: int, lastLength: int, lastHash: string} $from
     * @param callable(array<string,mixed>): void                                                    $visit
     *
     * @return array{dev: int, ino: int, offset: int, maxSeq: int, lastLength: int, lastHash: string}|null
     */
    private function readLines($handle, array $from, callable $visit): ?array
    {
        $at = $from;
        $finished = true;
        while (($raw = fgets($handle)) !== false) {
            $finished = str_ends_with($raw, "\n");
            $line = trim($raw);
            if ($line !== '') {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $at['maxSeq'] = max($at['maxSeq'], (int) ($decoded['seq'] ?? 0));
                    $visit($decoded);
                }
            }
            // Only the last line can lack its newline, and then no position is answered at all.
            $at['offset'] += \strlen($raw);
            $at['lastLength'] = \strlen($raw);
            $at['lastHash'] = hash('xxh128', $raw);
        }

        return $finished ? $at : null;
    }

    /**
     * Where a read of this handle starts: the file it is (device and inode), and nothing read yet.
     *
     * @param resource $handle
     *
     * @return array{dev: int, ino: int, offset: int, maxSeq: int, lastLength: int, lastHash: string}
     */
    private function identity($handle): array
    {
        $stat = fstat($handle);

        return [
            'dev' => $stat === false ? 0 : $stat['dev'],
            'ino' => $stat === false ? 0 : $stat['ino'],
            'offset' => 0,
            'maxSeq' => 0,
            'lastLength' => 0,
            'lastHash' => '',
        ];
    }

    /**
     * Drop the remembered read and the kept stream; always `false`, so a caller can return it.
     */
    private function forget(): bool
    {
        $this->position = null;
        $this->kept = null;

        return false;
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
