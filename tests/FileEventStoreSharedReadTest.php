<?php

declare(strict_types=1);

namespace Milpa\EventStore\Tests;

use Milpa\EventStore\Event;
use Milpa\EventStore\FileEventStore;
use PHPUnit\Framework\TestCase;

/**
 * One read of the log, shared: an instance remembers where its last read ended and the last stream it
 * replayed, and asks the file only for what was appended since (greenhouse evidence/1045). An agent leg
 * read its own 21 MB session 28 times in one step and held four decoded copies at once.
 *
 * Every test here compares against what a FRESH instance reads from the same file — the only truth the
 * log has — and counts the bytes each call reads through {@see CountingFile}, so «not read again» is a
 * number, not an inference.
 */
final class FileEventStoreSharedReadTest extends TestCase
{
    private string $file;

    private string $path;

    protected function setUp(): void
    {
        CountingFile::register();
        $this->file = sys_get_temp_dir() . '/event-store-shared-' . uniqid('', true) . '.jsonl';
        $this->path = CountingFile::SCHEME . '://' . $this->file;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testARepeatedReplayHandsTheSameEventsWithoutReadingTheLogAgain(): void
    {
        $this->seed(['A' => 40, 'B' => 40]);
        $store = new FileEventStore($this->path);

        CountingFile::$read = 0;
        $first = $store->replay('A');
        $whole = CountingFile::$read;

        CountingFile::$read = 0;
        $again = $store->replay('A');

        self::assertSame(filesize($this->file), $whole, 'the first replay reads the whole log');
        self::assertCount(40, $again);
        self::assertSame($first[0], $again[0], 'the same Event objects, not a second decoded copy');
        self::assertLessThan(200, CountingFile::$read, 'only the last line is read again, to recognise the file');
    }

    public function testWhatAnotherInstanceAppendsIsReadFromWhereTheLastReadEnded(): void
    {
        $this->seed(['A' => 30, 'B' => 30]);
        $store = new FileEventStore($this->path);
        $store->replay('A');
        $before = filesize($this->file);

        $other = new FileEventStore($this->file);
        $other->append(new Event('A', 'late', ['n' => 1], $other->nextSeq()));
        $other->append(new Event('B', 'late', ['n' => 2], $other->nextSeq()));
        clearstatcache();

        CountingFile::$read = 0;
        $replayed = $store->replay('A');

        self::assertEquals((new FileEventStore($this->file))->replay('A'), $replayed);
        self::assertSame('late', $replayed[\count($replayed) - 1]->type);
        // The stream buffer reads ahead: the last line's check pulls the tail too, and the seek reads it again.
        self::assertLessThanOrEqual(2 * (200 + filesize($this->file) - $before), CountingFile::$read, 'the last line and what was appended — not the log');
        self::assertLessThan($before / 4, CountingFile::$read);
        self::assertSame(63, $store->nextSeq(), 'the next seq counts what another instance appended');
    }

    public function testAnEventAppendedOutOfSeqOrderIsSortedLikeAFreshReplay(): void
    {
        $this->seed(['A' => 5]);
        $store = new FileEventStore($this->path);
        $store->replay('A');

        (new FileEventStore($this->file))->append(new Event('A', 'early', [], 3));

        $replayed = $store->replay('A');

        self::assertEquals((new FileEventStore($this->file))->replay('A'), $replayed);
        self::assertSame([1, 2, 3, 3, 4, 5], array_map(static fn (Event $e): int => $e->seq, $replayed));
    }

    public function testAFileReplacedAtTheSamePathIsReadFromTheStart(): void
    {
        $this->seed(['A' => 5]);
        $store = new FileEventStore($this->path);
        $store->replay('A');

        // Written beside it and renamed over it: a different inode for certain, the same size and last line.
        $beside = $this->file . '.new';
        $rows = file($this->file);
        self::assertIsArray($rows);
        $rows[0] = str_replace('"n":0', '"n":9', $rows[0]);
        file_put_contents($beside, implode('', $rows));
        rename($beside, $this->file);

        self::assertSame(9, $store->replay('A')[0]->payload['n']);
    }

    public function testAShorterFileIsReadFromTheStart(): void
    {
        $this->seed(['A' => 10]);
        $store = new FileEventStore($this->path);
        $store->replay('A');

        $rows = file($this->file);
        self::assertIsArray($rows);
        $handle = fopen($this->file, 'r+');
        self::assertIsResource($handle);
        ftruncate($handle, 0);
        fwrite($handle, implode('', \array_slice($rows, 0, 4)));
        fclose($handle);

        self::assertCount(4, $store->replay('A'));
        self::assertSame(5, $store->nextSeq());
    }

    public function testALogWhoseLastReadLineChangedIsReadFromTheStart(): void
    {
        $this->seed(['A' => 10]);
        $store = new FileEventStore($this->path);
        $store->replay('A');

        // Same inode, and longer than what was read — only the last line read tells it is another log.
        $rows = file($this->file);
        self::assertIsArray($rows);
        $rows[9] = str_replace('"n":9', '"n":8', $rows[9]);
        $handle = fopen($this->file, 'r+');
        self::assertIsResource($handle);
        fwrite($handle, implode('', $rows) . $this->row('A', 11, 'extra'));
        fclose($handle);

        $replayed = $store->replay('A');

        self::assertEquals((new FileEventStore($this->file))->replay('A'), $replayed);
        self::assertSame(8, $replayed[9]->payload['n']);
    }

    public function testALastLineWithoutANewlineIsNeverWhereTheNextReadStarts(): void
    {
        $this->seed(['A' => 3]);
        file_put_contents($this->file, rtrim($this->row('A', 4, 'unfinished')), FILE_APPEND);
        $store = new FileEventStore($this->path);

        self::assertCount(4, $store->replay('A'), 'read like before: the line decodes, so it is an event');

        file_put_contents($this->file, "\n" . $this->row('A', 5, 'after'), FILE_APPEND);

        self::assertEquals((new FileEventStore($this->file))->replay('A'), $store->replay('A'));
        self::assertCount(5, $store->replay('A'), 'the unfinished line is not counted twice');
    }

    public function testAnAppendAfterAnUnfinishedLineFailsForEveryReaderAlike(): void
    {
        $this->seed(['A' => 3]);
        file_put_contents($this->file, rtrim($this->row('A', 4, 'unfinished')), FILE_APPEND);
        $store = new FileEventStore($this->path);
        $store->replay('A');

        // The next append lands on the unfinished line: the log now holds one line that is two events.
        (new FileEventStore($this->file))->append(new Event('A', 'after', [], 5));

        try {
            (new FileEventStore($this->file))->replay('A');
            self::fail('a fresh reader cannot decode the merged line');
        } catch (\JsonException) {
        }
        $this->expectException(\JsonException::class);
        $store->replay('A');
    }

    public function testAnotherStreamReplacesTheKeptOne(): void
    {
        $this->seed(['A' => 5, 'B' => 5]);
        $store = new FileEventStore($this->path);
        $store->replay('A');
        $store->replay('B');

        (new FileEventStore($this->file))->append(new Event('A', 'late', [], 11));

        self::assertEquals((new FileEventStore($this->file))->replay('A'), $store->replay('A'));
        self::assertEquals((new FileEventStore($this->file))->replay('B'), $store->replay('B'));
    }

    public function testNextSeqAloneRemembersWhereItStopped(): void
    {
        $this->seed(['A' => 50]);
        $store = new FileEventStore($this->path);
        self::assertSame(51, $store->nextSeq());

        (new FileEventStore($this->file))->append(new Event('A', 'late', [], 51));

        CountingFile::$read = 0;
        self::assertSame(52, $store->nextSeq());
        self::assertLessThan(400, CountingFile::$read);
    }

    /**
     * Interleaved appends by other instances and replays of two streams, against a fresh instance at every
     * step — including seqs appended out of order.
     */
    public function testInterleavedAppendsAndReplaysAlwaysMatchAFreshInstance(): void
    {
        mt_srand(1045);
        $store = new FileEventStore($this->path);
        $seq = 0;
        for ($step = 0; $step < 300; ++$step) {
            $stream = mt_rand(0, 2) === 0 ? 'B' : 'A';
            $action = mt_rand(0, 3);
            if ($action === 0) {
                $writer = mt_rand(0, 1) === 0 ? $store : new FileEventStore($this->file);
                $next = mt_rand(0, 9) === 0 ? max(1, $seq - 2) : ++$seq;
                $writer->append(new Event($stream, 'e' . $step, ['step' => $step], $next));
            } elseif ($action === 1) {
                self::assertSame((new FileEventStore($this->file))->nextSeq(), $store->nextSeq(), "nextSeq at step {$step}");
            } else {
                self::assertEquals((new FileEventStore($this->file))->replay($stream), $store->replay($stream), "replay {$stream} at step {$step}");
            }
        }
    }

    /**
     * Two replays of the same stream share one decoded copy: holding both costs what holding one does.
     */
    public function testHoldingTwoReplaysCostsOneCopy(): void
    {
        $rows = '';
        for ($i = 1; $i <= 1500; ++$i) {
            $rows .= $this->row('A', $i, str_repeat('r', 2000));
        }
        file_put_contents($this->file, $rows);
        $store = new FileEventStore($this->file);

        $base = memory_get_usage();
        $first = $store->replay('A');
        $one = memory_get_usage() - $base;
        $second = $store->replay('A');
        $two = memory_get_usage() - $base;

        self::assertCount(1500, $first);
        self::assertCount(1500, $second);
        self::assertLessThan($one * 1.1, $two, sprintf('one copy %.1f MB, two replays held %.1f MB', $one / 1048576, $two / 1048576));
    }

    /**
     * @param array<string, int> $streams stream id → how many events, interleaved one of each in turn
     */
    private function seed(array $streams): void
    {
        $rows = '';
        $seq = 0;
        $counts = array_fill_keys(array_keys($streams), 0);
        while (array_sum($counts) < array_sum($streams)) {
            foreach ($streams as $id => $total) {
                if ($counts[$id] < $total) {
                    $rows .= $this->row((string) $id, ++$seq, 'seeded', ['n' => $counts[$id]++]);
                }
            }
        }
        file_put_contents($this->file, $rows);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function row(string $stream, int $seq, string $type, ?array $payload = null): string
    {
        return json_encode(['stream_id' => $stream, 'type' => $type, 'payload' => $payload ?? ['n' => $seq], 'seq' => $seq, 'recorded_at' => null], JSON_THROW_ON_ERROR) . "\n";
    }
}

/**
 * A pass-through stream wrapper over a real file that counts the bytes read through it — the instrument that
 * says whether a call read the log again. `counting:///tmp/x` is `/tmp/x`.
 */
final class CountingFile
{
    public const SCHEME = 'counting';

    public static int $read = 0;

    /** @var resource|null */
    public $context;

    /** @var resource */
    private $handle;

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public function stream_open(string $path, string $mode): bool
    {
        $handle = fopen(self::real($path), $mode);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $bytes = fread($this->handle, $count);
        self::$read += $bytes === false ? 0 : \strlen($bytes);

        return $bytes;
    }

    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_lock(int $operation): bool
    {
        return flock($this->handle, $operation);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $real = self::real($path);
        clearstatcache(true, $real);

        return file_exists($real) ? stat($real) : false;
    }

    private static function real(string $path): string
    {
        return substr($path, \strlen(self::SCHEME . '://'));
    }
}
