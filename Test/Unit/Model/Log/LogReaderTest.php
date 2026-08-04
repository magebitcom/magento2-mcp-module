<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Log;

use Magebit\Mcp\Model\Log\LogReader;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

class LogReaderTest extends TestCase
{
    private string $dir;

    private LogReader $reader;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'magebit-mcp-reader-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->reader = new LogReader(new FileDriver());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testTailReturnsTheLastLinesInFileOrder(): void
    {
        $path = $this->write('system.log', $this->numberedLines(1, 500));

        $result = $this->reader->tail($path, 3);

        self::assertSame(['line 498', 'line 499', 'line 500'], $result['lines']);
        self::assertSame(3, $result['returned_lines']);
        // 500 short lines fit inside the first backwards chunk.
        self::assertTrue($result['reached_start_of_file']);
    }

    public function testTailDoesNotEmitAPartialFirstLine(): void
    {
        // 4000 lines pushes the backwards read past one chunk boundary, so the
        // buffer starts mid-line unless the partial head is dropped.
        $path = $this->write('system.log', $this->numberedLines(1, 4000));

        foreach ($this->reader->tail($path, 5)['lines'] as $line) {
            self::assertMatchesRegularExpression('/^line \d+$/', $line);
        }
    }

    public function testTailReadsOnlyTheEndOfTheFile(): void
    {
        $path = $this->write('system.log', $this->numberedLines(1, 20000));

        $result = $this->reader->tail($path, 2);

        self::assertLessThan((int) filesize($path), $result['bytes_read']);
        self::assertSame(['line 19999', 'line 20000'], $result['lines']);
    }

    public function testTailReturnsEverythingForAShortFile(): void
    {
        $path = $this->write('system.log', "alpha\nbeta\n");

        $result = $this->reader->tail($path, 200);

        self::assertSame(['alpha', 'beta'], $result['lines']);
        self::assertTrue($result['reached_start_of_file']);
    }

    public function testTailHandlesAFileWithoutATrailingNewline(): void
    {
        $path = $this->write('system.log', "alpha\nbeta");

        self::assertSame(['alpha', 'beta'], $this->reader->tail($path, 10)['lines']);
    }

    public function testTailHandlesCarriageReturns(): void
    {
        $path = $this->write('system.log', "alpha\r\nbeta\r\n");

        self::assertSame(['alpha', 'beta'], $this->reader->tail($path, 10)['lines']);
    }

    public function testTailOnAnEmptyFileReturnsNoLines(): void
    {
        $path = $this->write('system.log', '');

        $result = $this->reader->tail($path, 10);

        self::assertSame([], $result['lines']);
        self::assertTrue($result['reached_start_of_file']);
    }

    public function testTailTruncatesAPathologicallyLongLine(): void
    {
        $path = $this->write('system.log', str_repeat('x', 50000) . "\n");

        $result = $this->reader->tail($path, 10);

        self::assertCount(1, $result['lines']);
        self::assertLessThan(50000, strlen($result['lines'][0]));
        self::assertStringContainsString('truncated', $result['lines'][0]);
        self::assertSame(1, $result['truncated_lines']);
    }

    public function testTailBoundsTheTotalOutputSize(): void
    {
        // 2000 lines of 2 KiB each is 4 MiB of candidate output; the budget must
        // cut it down while still returning the newest lines.
        $path = $this->write('system.log', str_repeat(str_repeat('y', 2000) . "\n", 2000));

        $result = $this->reader->tail($path, 2000);

        self::assertTrue($result['output_truncated']);
        self::assertLessThan(2000, $result['returned_lines']);
        $bytes = array_sum(array_map('strlen', $result['lines']));
        self::assertLessThanOrEqual(LogReader::MAX_OUTPUT_BYTES, $bytes);
    }

    public function testTailOnAMissingFileRaises(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->tail($this->dir . '/absent.log', 10);
    }

    public function testGrepFindsLiteralSubstringMatches(): void
    {
        $path = $this->write('system.log', "alpha\nbeta ERROR here\ngamma\ndelta ERROR too\n");

        $result = $this->reader->grep($path, 'ERROR', true, 100, 0);

        self::assertSame(2, $result['match_count']);
        self::assertSame([2, 4], array_column($result['lines'], 'line'));
        self::assertSame('beta ERROR here', $result['lines'][0]['text']);
        self::assertFalse($result['truncated']);
        self::assertSame('eof', $result['stop_reason']);
    }

    public function testGrepIsCaseSensitiveWhenAsked(): void
    {
        $path = $this->write('system.log', "Error one\nerror two\n");

        self::assertSame(1, $this->reader->grep($path, 'Error', true, 100, 0)['match_count']);
        self::assertSame(2, $this->reader->grep($path, 'Error', false, 100, 0)['match_count']);
    }

    public function testGrepTreatsTheNeedleAsALiteralNotARegex(): void
    {
        $path = $this->write('system.log', "plain text\nliteral a.c here\nabc here\n");

        $result = $this->reader->grep($path, 'a.c', false, 100, 0);

        self::assertSame(1, $result['match_count']);
        self::assertSame(2, $result['lines'][0]['line']);
    }

    public function testGrepStopsAtMaxMatchesAndReportsTruncation(): void
    {
        $path = $this->write('system.log', str_repeat("ERROR boom\n", 50));

        $result = $this->reader->grep($path, 'ERROR', true, 5, 0);

        self::assertSame(5, $result['match_count']);
        self::assertTrue($result['truncated']);
        self::assertSame('max_matches', $result['stop_reason']);
        self::assertLessThan(50, $result['lines_scanned']);
    }

    public function testGrepReturnsSurroundingContextLines(): void
    {
        $path = $this->write('system.log', "one\ntwo\nthree ERROR\nfour\nfive\n");

        $result = $this->reader->grep($path, 'ERROR', true, 100, 1);

        self::assertSame([2, 3, 4], array_column($result['lines'], 'line'));
        self::assertSame([false, true, false], array_column($result['lines'], 'match'));
        self::assertSame(1, $result['match_count']);
    }

    public function testGrepNeverRepeatsALineSharedBetweenTwoContextWindows(): void
    {
        $path = $this->write('system.log', "one\nERROR a\nmiddle\nERROR b\nfive\n");

        $result = $this->reader->grep($path, 'ERROR', true, 100, 2);

        self::assertSame([1, 2, 3, 4, 5], array_column($result['lines'], 'line'));
        self::assertSame(2, $result['match_count']);
    }

    public function testGrepTruncatesAPathologicallyLongMatchingLine(): void
    {
        $path = $this->write('system.log', 'ERROR ' . str_repeat('z', 50000) . "\n");

        $result = $this->reader->grep($path, 'ERROR', true, 100, 0);

        self::assertLessThan(50000, strlen($result['lines'][0]['text']));
        self::assertSame(1, $result['truncated_lines']);
    }

    public function testGrepBoundsTheTotalOutputSize(): void
    {
        $path = $this->write('system.log', str_repeat('ERROR ' . str_repeat('q', 2000) . "\n", 1000));

        $result = $this->reader->grep($path, 'ERROR', true, 1000, 0);

        self::assertTrue($result['truncated']);
        self::assertSame('output_limit', $result['stop_reason']);
        $bytes = array_sum(array_map('strlen', array_column($result['lines'], 'text')));
        self::assertLessThanOrEqual(LogReader::MAX_OUTPUT_BYTES, $bytes);
    }

    public function testGrepWithNoMatchesReportsCleanly(): void
    {
        $path = $this->write('system.log', "alpha\nbeta\n");

        $result = $this->reader->grep($path, 'ERROR', true, 100, 2);

        self::assertSame([], $result['lines']);
        self::assertSame(0, $result['match_count']);
        self::assertSame(2, $result['lines_scanned']);
        self::assertFalse($result['truncated']);
    }

    public function testGrepOnAMissingFileRaises(): void
    {
        $this->expectException(LocalizedException::class);
        $this->reader->grep($this->dir . '/absent.log', 'x', false, 10, 0);
    }

    public function testGrepFindsMatchesBeyondTheFirstReadChunk(): void
    {
        // The chunked reader takes a fast path over chunks with no hit; a match
        // far past the first 64 KiB proves the slow path is still reached and
        // that line numbering survives the chunk accounting.
        $body = $this->numberedLines(1, 20000) . "tail ERROR here\n";
        $path = $this->write('system.log', $body);

        $result = $this->reader->grep($path, 'ERROR', true, 10, 0);

        self::assertSame(1, $result['match_count']);
        self::assertSame(20001, $result['lines'][0]['line']);
        self::assertSame(20001, $result['lines_scanned']);
    }

    public function testGrepMatchesALineStraddlingAChunkBoundary(): void
    {
        // Pad so the marker lands across the 64 KiB read boundary — the carry
        // between chunks is the only thing that can stitch it back together.
        $marker = 'STRADDLE-MARKER';
        // Line 1 ends at byte 65524, so line 2 starts at 65525 and the marker
        // spans bytes 65525-65539 — the first 64 KiB read stops in the middle of
        // it, and only the carry between chunks can reunite the two halves.
        $path = $this->write('system.log', str_repeat('p', 65524) . "\n" . $marker . " end\n");

        $result = $this->reader->grep($path, $marker, true, 10, 0);

        self::assertSame(1, $result['match_count']);
        self::assertSame(2, $result['lines'][0]['line']);
        self::assertSame($marker . ' end', $result['lines'][0]['text']);
    }

    public function testGrepCountsLinesConsistentlyAcrossChunks(): void
    {
        $path = $this->write('system.log', $this->numberedLines(1, 20000));

        // 'line 19999' can only be attributed to the right number if every
        // preceding chunk contributed exactly its own line count.
        $result = $this->reader->grep($path, 'line 19999', true, 10, 0);

        self::assertSame(1, $result['match_count']);
        self::assertSame(19999, $result['lines'][0]['line']);
    }

    public function testGrepReportsCoveringTheWholeFile(): void
    {
        $path = $this->write('system.log', "alpha\nERROR beta\n");

        $result = $this->reader->grep($path, 'ERROR', true, 100, 0);

        self::assertSame(0, $result['scan_window']['start_byte']);
        self::assertTrue($result['scan_window']['covers_whole_file']);
        self::assertSame(17, $result['file_size_bytes']);
    }

    public function testGrepDoesNotClaimWholeFileCoverageWhenItStoppedEarly(): void
    {
        $path = $this->write('system.log', str_repeat("ERROR boom\n", 20));

        $result = $this->reader->grep($path, 'ERROR', true, 2, 0);

        self::assertFalse($result['scan_window']['covers_whole_file']);
    }

    public function testGrepStopsOnTheWallClockBudget(): void
    {
        $reader = new LogReader(new FileDriver(), 0.0);
        $path = $this->write('system.log', str_repeat("ERROR boom\n", 10));

        $result = $reader->grep($path, 'ERROR', true, 100, 0);

        self::assertSame('time_limit', $result['stop_reason']);
        self::assertTrue($result['truncated']);
    }

    public function testGrepKeepsMemoryFlatOnAFileThatIsOneEnormousLine(): void
    {
        // 3 MiB with no line break at all: the carry budget must force a split
        // rather than buffering the whole file to look for a newline.
        $path = $this->write('system.log', str_repeat('n', 3145728));
        $before = memory_get_usage(true);

        $result = $this->reader->grep($path, 'nn', true, 5, 0);

        self::assertLessThan(8388608, memory_get_usage(true) - $before);
        self::assertGreaterThan(0, $result['match_count']);
        foreach ($result['lines'] as $line) {
            self::assertLessThanOrEqual(
                LogReader::MAX_LINE_LENGTH + 32,
                strlen($line['text']),
                'Every returned line must respect the per-line cap.'
            );
        }
    }

    public function testTailKeepsMemoryFlatOnAFileThatIsOneEnormousLine(): void
    {
        $path = $this->write('system.log', str_repeat('n', 3145728));
        $before = memory_get_usage(true);

        $result = $this->reader->tail($path, 200);

        self::assertLessThan(16777216, memory_get_usage(true) - $before);
        self::assertLessThanOrEqual(1, count($result['lines']));
    }

    /**
     * @param string $name
     * @param string $contents
     * @return string
     */
    private function write(string $name, string $contents): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param int $from
     * @param int $to
     * @return string
     */
    private function numberedLines(int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            $out .= 'line ' . $i . "\n";
        }

        return $out;
    }
    /**
     * @param string $path
     * @return void
     */
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }

}
