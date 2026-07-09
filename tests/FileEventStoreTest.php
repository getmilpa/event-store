<?php

declare(strict_types=1);

namespace Milpa\EventStore\Tests;

use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FileEventStore;

final class FileEventStoreTest extends EventStoreContractTestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/event-store-' . uniqid('', true) . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    protected function createStore(): EventStoreInterface
    {
        return new FileEventStore($this->path);
    }

    public function testTheLogFileHasOneLinePerAppendedEvent(): void
    {
        $store = new FileEventStore($this->path);

        $store->append(new Event('A', 'StreamStarted', ['post_id' => 1], $store->nextSeq()));
        $store->append(new Event('B', 'StreamStarted', ['post_id' => 2], $store->nextSeq()));
        $store->append(new Event('A', 'submit', [], $store->nextSeq()));
        $store->append(new Event('B', 'submit', [], $store->nextSeq()));
        $store->append(new Event('A', 'grant', [], $store->nextSeq()));

        $lines = array_filter(explode("\n", (string) file_get_contents($this->path)), static fn (string $l): bool => trim($l) !== '');

        $this->assertCount(5, $lines);
    }

    public function testAFreshFileEventStoreOverTheSameFileReplaysIdentically(): void
    {
        $store = new FileEventStore($this->path);
        $store->append(new Event('A', 'StreamStarted', ['post_id' => 1], $store->nextSeq()));
        $store->append(new Event('A', 'submit', [], $store->nextSeq()));

        $original = $store->replay('A');

        $fresh = new FileEventStore($this->path);
        $replayed = $fresh->replay('A');

        $this->assertEquals($original, $replayed);
        $this->assertSame(3, $fresh->nextSeq(), 'nextSeq must continue from the persisted log, not reset');
    }

    public function testNextSeqIsOneForAMissingLogFile(): void
    {
        $store = new FileEventStore($this->path);

        $this->assertFalse(is_file($this->path));
        $this->assertSame(1, $store->nextSeq());
    }

    public function testAppendCreatesTheParentDirectoryWhenMissing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/event-store-nested-' . uniqid('', true) . '/events.jsonl';
        $store = new FileEventStore($nestedPath);

        $store->append(new Event('A', 'StreamStarted', [], $store->nextSeq()));

        $this->assertFileExists($nestedPath);
        $this->assertCount(1, $store->replay('A'));

        @unlink($nestedPath);
        @rmdir(\dirname($nestedPath));
    }
}
