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
 * Immutable fact appended to an {@see EventStoreInterface}'s log. A stream's state is never stored
 * directly — it is always the fold of its `Event`s, so this VO is the only unit of truth an event
 * store persists.
 *
 * `streamId` groups events into independent, individually replayable streams (a process instance, an
 * aggregate, an entity id — any identifier the consumer chooses to partition its log by). `type` is a
 * free-form name the consumer defines and interprets; the store itself never inspects it.
 *
 * `recordedAt` is the wall-clock observation made by the process constructing the event. It is not a
 * verified occurrence time or a duration: its trust is exactly the trust placed in that process and
 * its clock. A `null` value is reserved for records written before this observation existed; readers
 * must preserve that gap rather than manufacture a time from file metadata or payload contents.
 */
final readonly class Event
{
    private const string RECORDED_AT_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @param string              $streamId   the stream this event belongs to
     * @param string              $type       the event's name, defined and interpreted by the consumer
     * @param array<string,mixed> $payload    data carried by this event
     * @param int                 $seq        monotonic position of this event within its event store
     * @param ?\DateTimeImmutable $recordedAt wall-clock observation by the constructing process;
     *                                        `null` means the record predates this field
     */
    public function __construct(
        public string $streamId,
        public string $type,
        public array $payload,
        public int $seq,
        public ?\DateTimeImmutable $recordedAt = new \DateTimeImmutable(),
    ) {
    }

    /**
     * Projects this event into a plain array suitable for JSON encoding (JSONL round-trip).
     *
     * @return array{stream_id: string, type: string, payload: array<string,mixed>, seq: int, recorded_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'stream_id' => $this->streamId,
            'type' => $this->type,
            'payload' => $this->payload,
            'seq' => $this->seq,
            'recorded_at' => $this->recordedAt
                ?->setTimezone(new \DateTimeZone('UTC'))
                ->format(self::RECORDED_AT_FORMAT),
        ];
    }

    /**
     * Reconstructs an event from the array shape produced by {@see self::toArray()}.
     *
     * @param array{stream_id: string, type: string, payload: array<string,mixed>, seq: int, recorded_at?: ?string} $row
     */
    public static function fromArray(array $row): self
    {
        $recordedAt = $row['recorded_at'] ?? null;

        return new self(
            $row['stream_id'],
            $row['type'],
            $row['payload'],
            $row['seq'],
            $recordedAt === null ? null : new \DateTimeImmutable($recordedAt),
        );
    }
}
