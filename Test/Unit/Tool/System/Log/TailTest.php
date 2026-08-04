<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Log;

use Magebit\Mcp\Model\Log\LogFileResolver;
use Magebit\Mcp\Model\Log\LogReader;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Log\Tail;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

class TailTest extends TestCase
{
    private string $dir;

    private Tail $tool;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'magebit-mcp-tail-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $body = '';
        for ($i = 1; $i <= 400; $i++) {
            $body .= 'line ' . $i . "\n";
        }
        file_put_contents($this->dir . '/system.log', $body);
        file_put_contents($this->dir . '/env.php', "secret\n");

        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(AppDirectoryList::LOG)->willReturn($this->dir);

        $this->tool = new Tail(new LogFileResolver($directoryList, new FileDriver()), new LogReader(new FileDriver()));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testMetadata(): void
    {
        self::assertSame('system.log.tail', $this->tool->getName());
        self::assertSame('Magebit_Mcp::tool_system_log_tail', $this->tool->getAclResource());
        self::assertSame(WriteMode::READ, $this->tool->getWriteMode());
        self::assertFalse($this->tool->getConfirmationRequired());
    }

    public function testSchema(): void
    {
        self::assertSame([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'file' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'description' => 'Log file name exactly as reported by '
                        . '`system.log.list`, e.g. `exception.log`. A plain file '
                        . 'name only — no directories or paths.',
                ],
                'lines' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 2000,
                    'description' => 'How many trailing lines to return. Default 200.',
                ],
            ],
            'required' => ['file'],
            'additionalProperties' => false,
        ], $this->tool->getInputSchema());
    }

    public function testReturnsTheRequestedNumberOfTrailingLines(): void
    {
        $payload = $this->execute(['file' => 'system.log', 'lines' => 3]);

        self::assertSame('system.log', $payload['file']);
        self::assertSame(3, $payload['requested_lines']);
        self::assertSame(['line 398', 'line 399', 'line 400'], $payload['lines']);
        self::assertSame(3, $payload['returned_lines']);
    }

    public function testDefaultsToTwoHundredLines(): void
    {
        $payload = $this->execute(['file' => 'system.log']);

        self::assertSame(200, $payload['requested_lines']);
        self::assertSame(200, $payload['returned_lines']);
    }

    public function testClampsAnOutOfRangeLineCount(): void
    {
        self::assertSame(2000, $this->execute(['file' => 'system.log', 'lines' => 999999])['requested_lines']);
        self::assertSame(1, $this->execute(['file' => 'system.log', 'lines' => 0])['requested_lines']);
    }

    public function testReportsTheAppliedCaps(): void
    {
        $payload = $this->execute(['file' => 'system.log', 'lines' => 5]);

        self::assertSame(2000, $payload['caps']['max_lines']);
        self::assertSame(LogReader::MAX_LINE_LENGTH, $payload['caps']['max_line_length']);
        self::assertSame(LogReader::MAX_OUTPUT_BYTES, $payload['caps']['max_output_bytes']);
        self::assertFalse($payload['output_truncated']);
        self::assertSame(0, $payload['long_lines_truncated']);
    }

    public function testAuditSummaryRecordsOnlyFileNameAndCounts(): void
    {
        $result = $this->tool->execute(['file' => 'system.log', 'lines' => 2]);

        self::assertSame(
            ['file' => 'system.log', 'requested_lines' => 2, 'returned_lines' => 2],
            $result->getAuditSummary()
        );
    }

    public function testRejectsAFileOutsideTheLogDirectory(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute(['file' => '../../app/etc/env.php']);
    }

    public function testRejectsANonLogFileInsideTheLogDirectory(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute(['file' => 'env.php']);
    }

    public function testRejectsAMissingFileArgument(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute([]);
    }

    /**
     * Declares the tool's whole response contract, so every assertion below is
     * checked against it rather than poking at an untyped array.
     *
     * @phpstan-param array<string, mixed> $arguments
     * @param array $arguments
     * @phpstan-return array{
     *     file: string,
     *     requested_lines: int,
     *     returned_lines: int,
     *     file_size_bytes: int,
     *     bytes_read: int,
     *     reached_start_of_file: bool,
     *     output_truncated: bool,
     *     long_lines_truncated: int,
     *     caps: array{
     *         max_lines: int,
     *         max_line_length: int,
     *         max_output_bytes: int,
     *         max_bytes_read: int
     *     },
     *     lines: list<string>
     * }
     * @return array
     */
    private function execute(array $arguments): array
    {
        $content = $this->tool->execute($arguments)->getContent();
        self::assertCount(1, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /**
         * @phpstan-var array{file: string, requested_lines: int, returned_lines: int,
         *     file_size_bytes: int, bytes_read: int, reached_start_of_file: bool,
         *     output_truncated: bool, long_lines_truncated: int,
         *     caps: array{max_lines: int, max_line_length: int, max_output_bytes: int,
         *     max_bytes_read: int}, lines: list<string>} $decoded
         */
        return $decoded;
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
