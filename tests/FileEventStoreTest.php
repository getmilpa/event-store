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

    public function testAnAppendedLineCarriesTheRecordedWallClock(): void
    {
        $store = new FileEventStore($this->path);
        $recordedAt = new \DateTimeImmutable('2026-08-17T20:00:00.123456-06:00');

        $store->append(new Event('A', 'StreamStarted', [], $store->nextSeq(), $recordedAt));

        $line = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('2026-08-18T02:00:00.123456Z', $line['recorded_at'] ?? null);
    }

    public function testALegacyLineReplaysWithAnUnknownWallClock(): void
    {
        file_put_contents($this->path, json_encode([
            'stream_id' => 'A',
            'type' => 'StreamStarted',
            'payload' => [],
            'seq' => 1,
        ], JSON_THROW_ON_ERROR) . "\n");

        $event = (new FileEventStore($this->path))->replay('A')[0];

        $this->assertNull($event->recordedAt);
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

    /**
     * Numbering the next event and replaying one stream read the log a line at a time (greenhouse evidence/1042):
     * a long agent session died at PHP's 128 MB on an append, because every append decoded the whole ledger —
     * every stream — to find the highest `seq`.
     */
    public function testNextSeqAndReplayDoNotHoldTheWholeLogInMemory(): void
    {
        $rows = '';
        for ($i = 1; $i <= 2000; ++$i) {
            $rows .= json_encode(['stream_id' => 'other', 'type' => 'noise', 'payload' => ['result' => str_repeat('r', 4000)], 'seq' => $i]) . "\n";
        }
        $rows .= json_encode(['stream_id' => 'mine', 'type' => 'kept', 'payload' => [], 'seq' => 2001]) . "\n";
        file_put_contents($this->path, $rows);
        $store = new FileEventStore($this->path);

        $base = memory_get_usage();
        memory_reset_peak_usage();
        $next = $store->nextSeq();
        $mine = $store->replay('mine');
        $peak = memory_get_peak_usage() - $base;

        $this->assertSame(2002, $next);
        $this->assertSame(['kept'], array_map(static fn (Event $e): string => $e->type, $mine));
        $this->assertLessThan(1024 * 1024, $peak, sprintf('this 8 MB log read whole cost ~10 MB (measured on 0.3.0); a line at a time it cost %.1f MB', $peak / 1048576));
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
