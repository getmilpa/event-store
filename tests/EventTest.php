<?php

declare(strict_types=1);

namespace Milpa\EventStore\Tests;

use Milpa\EventStore\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testNewEventsRecordTheAppenderWallClockByDefault(): void
    {
        $before = new \DateTimeImmutable();

        $event = new Event('stream-A', 'submit', ['post_id' => 1], 3);

        $after = new \DateTimeImmutable();

        $this->assertInstanceOf(\DateTimeImmutable::class, $event->recordedAt);
        $this->assertGreaterThanOrEqual($before, $event->recordedAt);
        $this->assertLessThanOrEqual($after, $event->recordedAt);
    }

    public function testToArrayProjectsAllFieldsUnderSnakeCaseKeys(): void
    {
        $event = new Event(
            'stream-A',
            'submit',
            ['post_id' => 1],
            3,
            new \DateTimeImmutable('2026-08-17T20:00:00.123456-06:00'),
        );

        $this->assertSame([
            'stream_id' => 'stream-A',
            'type' => 'submit',
            'payload' => ['post_id' => 1],
            'seq' => 3,
            'recorded_at' => '2026-08-18T02:00:00.123456Z',
        ], $event->toArray());
    }

    public function testFromArrayReconstructsAnEquivalentEvent(): void
    {
        $original = new Event('stream-A', 'submit', ['post_id' => 1], 3);

        $reconstructed = Event::fromArray($original->toArray());

        $this->assertEquals($original, $reconstructed);
    }

    public function testRoundTripsThroughJsonEncoding(): void
    {
        $original = new Event('stream-A', 'submit', ['post_id' => 1, 'nested' => ['a' => true]], 3);

        $line = json_encode($original->toArray(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $reconstructed = Event::fromArray($decoded);

        $this->assertEquals($original, $reconstructed);
    }

    public function testARecordWithoutWallClockReplaysAsUnknownInsteadOfNow(): void
    {
        $event = Event::fromArray([
            'stream_id' => 'legacy',
            'type' => 'submit',
            'payload' => [],
            'seq' => 1,
        ]);

        $this->assertNull($event->recordedAt);
        $this->assertNull($event->toArray()['recorded_at']);
    }
}
