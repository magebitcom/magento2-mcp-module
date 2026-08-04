<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Log;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

/**
 * Bounded, streaming reads over an already-validated log path. Store logs reach
 * multiple gigabytes, so nothing here ever loads a whole file and nothing scans
 * per line when it does not have to:
 *
 * - `tail()` walks backwards from the end in chunks, so its cost tracks the
 *   number of lines asked for, not the size of the file.
 * - `grep()` streams forward and tests each 64 KiB chunk with a single
 *   substring scan, splitting into lines only for the chunks that actually
 *   contain a hit. That prefilter roughly doubles throughput over a per-line
 *   read, which is what keeps a multi-gigabyte log searchable inside one
 *   request; a match cap or the deadline usually ends the scan far sooner.
 * - On a file larger than {@see MAX_SCAN_BYTES}, `grep()` searches the *newest*
 *   window rather than the oldest bytes, because that is where a diagnosis
 *   lives. `scan_window` reports exactly what was covered.
 *
 * Every read is additionally bounded by a wall-clock budget, a per-line length
 * cap, and a total output budget, so no single call can exhaust a PHP worker.
 */
class LogReader
{
    /** Longest single line returned; the rest is replaced with a marker. */
    public const MAX_LINE_LENGTH = 4000;

    /** Total payload budget across all returned lines. */
    public const MAX_OUTPUT_BYTES = 524288;

    /** Most bytes `tail()` will walk back through looking for line breaks. */
    public const MAX_TAIL_BYTES = 4194304;

    /** Widest window `grep()` will stream, anchored to the end of the file. */
    public const MAX_SCAN_BYTES = 1073741824;

    public const STOP_EOF = 'eof';
    public const STOP_MAX_MATCHES = 'max_matches';
    public const STOP_SCAN_LIMIT = 'scan_limit';
    public const STOP_OUTPUT_LIMIT = 'output_limit';
    public const STOP_TIME_LIMIT = 'time_limit';

    private const TRUNCATION_MARKER = '… [line truncated]';

    private const CHUNK_BYTES = 65536;

    /**
     * Ceiling on bytes held while waiting for a line break. A line longer than
     * this is force-split so memory stays flat on a malformed one-line file.
     */
    private const MAX_CARRY_BYTES = 1048576;

    /**
     * @param FileDriver $fileDriver
     * @param float $timeLimitSeconds Wall-clock budget for a single scan.
     */
    public function __construct(
        private readonly FileDriver $fileDriver,
        private readonly float $timeLimitSeconds = 10.0
    ) {
    }

    /**
     * Last $lines lines of the file, oldest-first, read from the end.
     *
     * @param string $path
     * @param int $lines
     * @phpstan-return array{
     *     lines: list<string>,
     *     returned_lines: int,
     *     truncated_lines: int,
     *     bytes_read: int,
     *     file_size_bytes: int,
     *     reached_start_of_file: bool,
     *     byte_limit_reached: bool,
     *     output_truncated: bool
     * }
     * @return array
     * @throws LocalizedException
     */
    public function tail(string $path, int $lines): array
    {
        $handle = $this->open($path);
        $position = 0;
        $size = 0;
        $bytesRead = 0;
        $byteLimitReached = false;
        $buffer = '';
        $newlineCount = 0;

        try {
            $this->fileDriver->fileSeek($handle, 0, SEEK_END);
            // fileTell is annotated `int` but wraps ftell(), which returns false
            // on failure; the same applies to fileRead/fread below.
            /** @var mixed $end */
            $end = $this->fileDriver->fileTell($handle);
            $size = is_numeric($end) ? (int) $end : 0;
            $position = $size;

            while ($position > 0) {
                if ($bytesRead >= self::MAX_TAIL_BYTES) {
                    $byteLimitReached = true;
                    break;
                }
                $length = (int) min(self::CHUNK_BYTES, $position, self::MAX_TAIL_BYTES - $bytesRead);
                $position -= $length;
                $this->fileDriver->fileSeek($handle, $position);
                /** @var mixed $chunk */
                $chunk = $this->fileDriver->fileRead($handle, $length);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $buffer = $chunk . $buffer;
                $bytesRead += strlen($chunk);
                // Counted per chunk and accumulated, rather than recounting the
                // whole (growing) buffer every iteration — that used to make
                // this loop O(chunks²) as the buffer approached the tail cap.
                $newlineCount += substr_count($chunk, "\n");
                // One more than requested: the first line in the buffer is
                // partial whenever the read did not reach the file start.
                if ($newlineCount > $lines) {
                    break;
                }
            }
        } catch (FileSystemException $e) {
            throw new LocalizedException(__('The log file could not be read.'), $e);
        } finally {
            $this->close($handle);
        }

        $rows = $this->splitTailBuffer($buffer, $position > 0);
        if (count($rows) > $lines) {
            $rows = array_slice($rows, -$lines);
        }

        // Built newest-first so the byte budget drops the oldest lines, then
        // reversed back into file order.
        $out = [];
        $truncatedLines = 0;
        $outputBytes = 0;
        $outputTruncated = false;
        foreach (array_reverse($rows) as $row) {
            [$capped, $wasTruncated] = $this->capLine($row);
            $cost = strlen($capped) + 1;
            if ($out !== [] && $outputBytes + $cost > self::MAX_OUTPUT_BYTES) {
                $outputTruncated = true;
                break;
            }
            $outputBytes += $cost;
            $truncatedLines += $wasTruncated ? 1 : 0;
            $out[] = $capped;
        }

        return [
            'lines' => array_reverse($out),
            'returned_lines' => count($out),
            'truncated_lines' => $truncatedLines,
            'bytes_read' => $bytesRead,
            'file_size_bytes' => $size,
            'reached_start_of_file' => $position === 0,
            'byte_limit_reached' => $byteLimitReached,
            'output_truncated' => $outputTruncated,
        ];
    }

    /**
     * Streams the file looking for a literal substring, stopping at the first
     * cap it hits. `stop_reason` says which one, so a client can tell a clean
     * end-of-file apart from a bounded scan.
     *
     * @param string $path
     * @param string $needle
     * @param bool $caseSensitive
     * @param int $maxMatches
     * @param int $contextLines
     * @phpstan-return array{
     *     lines: list<array{line: int, text: string, match: bool}>,
     *     match_count: int,
     *     lines_scanned: int,
     *     bytes_scanned: int,
     *     truncated_lines: int,
     *     truncated: bool,
     *     stop_reason: string,
     *     file_size_bytes: int,
     *     scan_window: array{start_byte: int, end_byte: int, covers_whole_file: bool}
     * }
     * @return array
     * @throws LocalizedException
     */
    public function grep(
        string $path,
        string $needle,
        bool $caseSensitive,
        int $maxMatches,
        int $contextLines
    ): array {
        if ($needle === '') {
            throw new LocalizedException(__('Argument "contains" must not be empty.'));
        }

        $handle = $this->open($path);
        $size = 0;
        $windowStart = 0;
        $lineNumber = 0;
        $bytesScanned = 0;
        $matchCount = 0;
        $truncatedLines = 0;
        $outputBytes = 0;
        $lastEmitted = 0;
        $after = 0;
        $stop = self::STOP_EOF;
        $deadline = microtime(true) + $this->timeLimitSeconds;
        $carry = '';
        /** @phpstan-var list<array{int, string}> $before */
        $before = [];
        /** @phpstan-var list<array{line: int, text: string, match: bool}> $out */
        $out = [];

        try {
            $this->fileDriver->fileSeek($handle, 0, SEEK_END);
            /** @var mixed $end */
            $end = $this->fileDriver->fileTell($handle);
            $size = is_numeric($end) ? (int) $end : 0;

            // On a file wider than the window, search the newest bytes: the
            // oldest gigabyte of a runaway log almost never holds the answer.
            $windowStart = $size > self::MAX_SCAN_BYTES ? $size - self::MAX_SCAN_BYTES : 0;
            $this->fileDriver->fileSeek($handle, $windowStart);
            if ($windowStart > 0) {
                $windowStart += $this->skipPartialLine($handle);
            }

            while (true) {
                if (microtime(true) > $deadline) {
                    $stop = self::STOP_TIME_LIMIT;
                    break;
                }
                if ($bytesScanned >= self::MAX_SCAN_BYTES) {
                    $stop = self::STOP_SCAN_LIMIT;
                    break;
                }

                /** @var mixed $read */
                $read = $this->fileDriver->fileRead($handle, self::CHUNK_BYTES);
                $chunk = is_string($read) ? $read : '';
                $atEof = $chunk === '';
                if ($atEof && $carry === '') {
                    break;
                }
                $bytesScanned += strlen($chunk);

                $buffer = $carry . $chunk;
                $carry = '';
                if ($atEof) {
                    // Whatever is left is a final line with no trailing newline.
                    $body = $buffer;
                } else {
                    $cut = strrpos($buffer, "\n");
                    if ($cut === false) {
                        if (strlen($buffer) < self::MAX_CARRY_BYTES) {
                            $carry = $buffer;
                            continue;
                        }
                        // Force-split a line longer than the carry budget rather
                        // than growing the buffer without limit.
                        $body = $buffer;
                    } else {
                        $body = substr($buffer, 0, $cut);
                        $carry = substr($buffer, $cut + 1);
                    }
                }

                // Fast path: one substring scan settles a whole chunk instead of
                // splitting ~700 lines that cannot match. Only available with no
                // context to carry, since context needs line boundaries.
                if ($contextLines === 0 && $after === 0 && !$this->contains($body, $needle, $caseSensitive)) {
                    $lineNumber += substr_count($body, "\n") + 1;
                    if ($atEof) {
                        break;
                    }
                    continue;
                }

                foreach (explode("\n", $body) as $rawLine) {
                    $lineNumber++;
                    $text = rtrim($rawLine, "\r");
                    $isMatch = $this->contains($text, $needle, $caseSensitive);

                    $emit = [];
                    if ($isMatch) {
                        foreach ($before as $buffered) {
                            $emit[] = [$buffered[0], $buffered[1], false];
                        }
                        $before = [];
                        $emit[] = [$lineNumber, $text, true];
                        $matchCount++;
                        $after = $contextLines;
                    } elseif ($after > 0) {
                        $emit[] = [$lineNumber, $text, false];
                        $after--;
                    } elseif ($contextLines > 0) {
                        $before[] = [$lineNumber, $text];
                        if (count($before) > $contextLines) {
                            array_shift($before);
                        }
                    }

                    foreach ($emit as [$number, $lineText, $flag]) {
                        if ($number <= $lastEmitted) {
                            continue;
                        }
                        [$capped, $wasTruncated] = $this->capLine($lineText);
                        $cost = strlen($capped) + 1;
                        if ($out !== [] && $outputBytes + $cost > self::MAX_OUTPUT_BYTES) {
                            $stop = self::STOP_OUTPUT_LIMIT;
                            break 3;
                        }
                        $outputBytes += $cost;
                        $truncatedLines += $wasTruncated ? 1 : 0;
                        $lastEmitted = $number;
                        $out[] = ['line' => $number, 'text' => $capped, 'match' => $flag];
                    }

                    if ($matchCount >= $maxMatches && $after === 0) {
                        $stop = self::STOP_MAX_MATCHES;
                        break 2;
                    }
                }

                if ($atEof) {
                    break;
                }
            }
        } catch (FileSystemException $e) {
            throw new LocalizedException(__('The log file could not be read.'), $e);
        } finally {
            $this->close($handle);
        }

        return [
            'lines' => $out,
            'match_count' => $matchCount,
            'lines_scanned' => $lineNumber,
            'bytes_scanned' => $bytesScanned,
            'truncated_lines' => $truncatedLines,
            'truncated' => $stop !== self::STOP_EOF,
            'stop_reason' => $stop,
            'file_size_bytes' => $size,
            'scan_window' => [
                'start_byte' => $windowStart,
                'end_byte' => $windowStart + $bytesScanned,
                'covers_whole_file' => $windowStart === 0 && $stop === self::STOP_EOF,
            ],
        ];
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @param bool $caseSensitive
     * @return bool
     */
    private function contains(string $haystack, string $needle, bool $caseSensitive): bool
    {
        return $caseSensitive
            ? str_contains($haystack, $needle)
            : stripos($haystack, $needle) !== false;
    }

    /**
     * Discards the partial line the window landed in, so the first reported line
     * is whole.
     *
     * @param resource $handle
     * @return int Bytes discarded.
     * @throws FileSystemException
     */
    private function skipPartialLine($handle): int
    {
        $skipped = 0;
        while ($skipped < self::MAX_CARRY_BYTES) {
            /** @var mixed $read */
            $read = $this->fileDriver->fileRead($handle, self::CHUNK_BYTES);
            if (!is_string($read) || $read === '') {
                return $skipped;
            }
            $chunk = $read;
            $newline = strpos($chunk, "\n");
            if ($newline !== false) {
                // Rewind to just past the break so the next read starts a line.
                $this->fileDriver->fileSeek($handle, -(strlen($chunk) - $newline - 1), SEEK_CUR);
                return $skipped + $newline + 1;
            }
            $skipped += strlen($chunk);
        }

        return $skipped;
    }

    /**
     * @param string $path
     * @return resource
     * @throws LocalizedException
     */
    private function open(string $path)
    {
        try {
            $handle = $this->fileDriver->fileOpen($path, 'rb');
        } catch (FileSystemException $e) {
            throw new LocalizedException(__('The log file could not be opened for reading.'), $e);
        }
        if (!is_resource($handle)) {
            throw new LocalizedException(__('The log file could not be opened for reading.'));
        }

        return $handle;
    }

    /**
     * @param resource $handle
     * @return void
     */
    private function close($handle): void
    {
        try {
            $this->fileDriver->fileClose($handle);
        } catch (FileSystemException) {
            // A read handle that will not close leaves nothing to recover, and
            // raising here would mask whatever the caller was already handling.
            return;
        }
    }

    /**
     * @param string $buffer
     * @param bool $headIsPartial
     * @phpstan-return list<string>
     * @return string[]
     */
    private function splitTailBuffer(string $buffer, bool $headIsPartial): array
    {
        if ($buffer === '') {
            return [];
        }
        $rows = explode("\n", $buffer);
        // A trailing newline yields an empty final element, which is not a line.
        if (end($rows) === '') {
            array_pop($rows);
        }
        if ($headIsPartial && $rows !== []) {
            array_shift($rows);
        }

        return array_map(static fn (string $row): string => rtrim($row, "\r"), $rows);
    }

    /**
     * @param string $line
     * @phpstan-return array{string, bool}
     * @return array
     */
    private function capLine(string $line): array
    {
        if (strlen($line) <= self::MAX_LINE_LENGTH) {
            return [$line, false];
        }
        // mb_strcut cuts on a character boundary, so a multi-byte sequence is
        // never split in half.
        $cut = mb_strcut($line, 0, self::MAX_LINE_LENGTH, 'UTF-8');

        return [$cut . self::TRUNCATION_MARKER, true];
    }
}
