<?php

declare(strict_types=1);

namespace Milpa\EventStore;

/**
 * Immutable fact appended to an {@see EventStoreInterface}'s log. A stream's state is never stored
 * directly — it is always the fold of its `Event`s, so this VO is the only unit of truth an event
 * store persists.
 *
 * `streamId` groups events into independent, individually replayable streams (a process instance, an
 * aggregate, an entity id — any identifier the consumer chooses to partition its log by). `type` is a
 * free-form name the consumer defines and interprets; the store itself never inspects it.
 */
final readonly class Event
{
    /**
     * @param string              $streamId the stream this event belongs to
     * @param string              $type     the event's name, defined and interpreted by the consumer
     * @param array<string,mixed> $payload  data carried by this event
     * @param int                 $seq      monotonic position of this event within its event store
     */
    public function __construct(
        public string $streamId,
        public string $type,
        public array $payload,
        public int $seq,
    ) {
    }

    /**
     * Projects this event into a plain array suitable for JSON encoding (JSONL round-trip).
     *
     * @return array{stream_id: string, type: string, payload: array<string,mixed>, seq: int}
     */
    public function toArray(): array
    {
        return [
            'stream_id' => $this->streamId,
            'type' => $this->type,
            'payload' => $this->payload,
            'seq' => $this->seq,
        ];
    }

    /**
     * Reconstructs an event from the array shape produced by {@see self::toArray()}.
     *
     * @param array{stream_id: string, type: string, payload: array<string,mixed>, seq: int} $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['stream_id'], $row['type'], $row['payload'], $row['seq']);
    }
}
