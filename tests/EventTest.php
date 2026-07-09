<?php

declare(strict_types=1);

namespace Milpa\EventStore\Tests;

use Milpa\EventStore\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testToArrayProjectsAllFieldsUnderSnakeCaseKeys(): void
    {
        $event = new Event('stream-A', 'submit', ['post_id' => 1], 3);

        $this->assertSame([
            'stream_id' => 'stream-A',
            'type' => 'submit',
            'payload' => ['post_id' => 1],
            'seq' => 3,
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
}
