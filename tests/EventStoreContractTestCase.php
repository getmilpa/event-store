<?php

declare(strict_types=1);

namespace Milpa\EventStore\Tests;

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Behavior every {@see EventStoreInterface} implementation must satisfy, regardless of storage
 * medium. Concrete test cases supply {@see self::createStore()}; implementation-specific behavior
 * (e.g. file durability) lives in the concrete test case, not here.
 */
abstract class EventStoreContractTestCase extends TestCase
{
    abstract protected function createStore(): EventStoreInterface;

    public function testReplayReturnsOnlyTheGivenStreamsEventsInSeqOrder(): void
    {
        $store = $this->createStore();

        $store->append(new Event('A', 'StreamStarted', ['post_id' => 1], $store->nextSeq()));
        $store->append(new Event('B', 'StreamStarted', ['post_id' => 2], $store->nextSeq()));
        $store->append(new Event('A', 'submit', [], $store->nextSeq()));
        $store->append(new Event('B', 'submit', [], $store->nextSeq()));
        $store->append(new Event('A', 'grant', [], $store->nextSeq()));

        $eventsA = $store->replay('A');

        $this->assertCount(3, $eventsA);
        $this->assertSame(['StreamStarted', 'submit', 'grant'], array_map(static fn (Event $e): string => $e->type, $eventsA));
        $this->assertSame([1, 3, 5], array_map(static fn (Event $e): int => $e->seq, $eventsA));
        foreach ($eventsA as $event) {
            $this->assertSame('A', $event->streamId);
        }
    }

    public function testTwoStreamsDoNotBleedIntoEachOther(): void
    {
        $store = $this->createStore();

        $store->append(new Event('A', 'StreamStarted', [], $store->nextSeq()));
        $store->append(new Event('B', 'StreamStarted', [], $store->nextSeq()));
        $store->append(new Event('A', 'submit', [], $store->nextSeq()));

        $eventsB = $store->replay('B');

        $this->assertCount(1, $eventsB);
        $this->assertSame('StreamStarted', $eventsB[0]->type);
        $this->assertSame('B', $eventsB[0]->streamId);
    }

    public function testSeqIsMonotonicAcrossTheWholeStoreNotPerStream(): void
    {
        $store = $this->createStore();

        $first = $store->nextSeq();
        $store->append(new Event('A', 'StreamStarted', [], $first));
        $second = $store->nextSeq();
        $store->append(new Event('B', 'StreamStarted', [], $second));
        $third = $store->nextSeq();
        $store->append(new Event('A', 'submit', [], $third));

        $this->assertSame([1, 2, 3], [$first, $second, $third]);
        $this->assertSame(4, $store->nextSeq());
    }

    public function testNextSeqIsOneForAnEmptyStore(): void
    {
        $store = $this->createStore();

        $this->assertSame(1, $store->nextSeq());
    }

    public function testReplayOfAnUnknownStreamIsEmpty(): void
    {
        $store = $this->createStore();
        $store->append(new Event('A', 'StreamStarted', [], $store->nextSeq()));

        $this->assertSame([], $store->replay('does-not-exist'));
    }

    public function testStreamsListsEveryDistinctStreamInFirstAppearanceOrder(): void
    {
        $store = $this->createStore();

        $store->append(new Event('B', 'StreamStarted', [], $store->nextSeq()));
        $store->append(new Event('A', 'StreamStarted', [], $store->nextSeq()));
        $store->append(new Event('B', 'submit', [], $store->nextSeq()));
        $store->append(new Event('B', 'submit', [], $store->nextSeq()));

        $this->assertSame(['B', 'A'], $store->streams());
    }

    public function testStreamsIsEmptyForAnEmptyStore(): void
    {
        $store = $this->createStore();

        $this->assertSame([], $store->streams());
    }
}
