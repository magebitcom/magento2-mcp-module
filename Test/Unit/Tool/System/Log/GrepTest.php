<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Log;

use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\Log\LogFileResolver;
use Magebit\Mcp\Model\Log\LogReader;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Log\Grep;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

class GrepTest extends TestCase
{
    private string $dir;

    private Grep $tool;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'magebit-mcp-grep-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        file_put_contents(
            $this->dir . '/system.log',
            "start\nCRITICAL payment failed\nnoise\ncritical retry failed\nend\n"
        );
        file_put_contents($this->dir . '/env.php', "secret\n");

        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getPath')->with(AppDirectoryList::LOG)->willReturn($this->dir);

        $this->tool = new Grep(new LogFileResolver($directoryList, new FileDriver(), $this->allowAllFixtures()), new LogReader(new FileDriver()));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testMetadata(): void
    {
        self::assertSame('system.log.grep', $this->tool->getName());
        self::assertSame('Magebit_Mcp::tool_system_log_grep', $this->tool->getAclResource());
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
                'contains' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 255,
                    'description' => 'Literal text to look for, at least 2 characters. '
                        . 'Not a regular expression and not a wildcard pattern.',
                ],
                'case_sensitive' => [
                    'type' => 'boolean',
                    'description' => 'Match letter case exactly. Defaults to `false`.',
                ],
                'max_matches' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 1000,
                    'description' => 'Stop after this many matching lines. Default 100.',
                ],
                'context_lines' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 5,
                    'description' => 'Lines of surrounding context to include on each '
                        . 'side of a match. Default 0.',
                ],
            ],
            'required' => ['file', 'contains'],
            'additionalProperties' => false,
        ], $this->tool->getInputSchema());
    }

    public function testDescriptionStatesTheNeedleIsLiteral(): void
    {
        self::assertStringContainsString('literal', strtolower($this->tool->getDescription()));
    }

    public function testMatchesCaseInsensitivelyByDefault(): void
    {
        $payload = $this->execute(['file' => 'system.log', 'contains' => 'CRITICAL']);

        self::assertSame(2, $payload['match_count']);
        self::assertFalse($payload['case_sensitive']);
        self::assertSame([2, 4], array_column($payload['matches'], 'line'));
    }

    public function testHonoursCaseSensitiveMatching(): void
    {
        $payload = $this->execute([
            'file' => 'system.log',
            'contains' => 'CRITICAL',
            'case_sensitive' => true,
        ]);

        self::assertSame(1, $payload['match_count']);
        self::assertSame('CRITICAL payment failed', $payload['matches'][0]['text']);
    }

    public function testReturnsContextLines(): void
    {
        $payload = $this->execute([
            'file' => 'system.log',
            'contains' => 'payment',
            'context_lines' => 1,
        ]);

        self::assertSame([1, 2, 3], array_column($payload['matches'], 'line'));
        self::assertSame([false, true, false], array_column($payload['matches'], 'match'));
    }

    public function testReportsTruncationWhenTheMatchCapIsHit(): void
    {
        $payload = $this->execute([
            'file' => 'system.log',
            'contains' => 'failed',
            'max_matches' => 1,
        ]);

        self::assertSame(1, $payload['match_count']);
        self::assertTrue($payload['truncated']);
        self::assertSame('max_matches', $payload['stop_reason']);
    }

    public function testNeverReportsMoreMatchesThanTheCapAllowsWithContextLines(): void
    {
        $payload = $this->execute([
            'file' => 'system.log',
            'contains' => 'failed',
            'max_matches' => 1,
            'context_lines' => 5,
        ]);

        self::assertLessThanOrEqual($payload['max_matches'], $payload['match_count']);
        self::assertSame(1, $payload['match_count']);
        self::assertCount(1, array_filter(array_column($payload['matches'], 'match')));
        self::assertTrue($payload['truncated']);
        self::assertSame('max_matches', $payload['stop_reason']);
    }

    public function testReportsTheAppliedCaps(): void
    {
        $payload = $this->execute(['file' => 'system.log', 'contains' => 'noise']);

        self::assertSame('system.log', $payload['file']);
        self::assertSame('noise', $payload['contains']);
        self::assertSame(100, $payload['max_matches']);
        self::assertSame(0, $payload['context_lines']);
        self::assertSame(LogReader::MAX_LINE_LENGTH, $payload['caps']['max_line_length']);
        self::assertSame(LogReader::MAX_SCAN_BYTES, $payload['caps']['max_scan_bytes']);
        self::assertSame(LogReader::MAX_OUTPUT_BYTES, $payload['caps']['max_output_bytes']);
    }

    public function testClampsOutOfRangeCaps(): void
    {
        $payload = $this->execute([
            'file' => 'system.log',
            'contains' => 'noise',
            'max_matches' => 999999,
            'context_lines' => 99,
        ]);

        self::assertSame(1000, $payload['max_matches']);
        self::assertSame(5, $payload['context_lines']);
    }

    public function testAuditSummaryNeverCarriesMatchedContentOrTheSearchTerm(): void
    {
        $result = $this->tool->execute(['file' => 'system.log', 'contains' => 'payment']);

        $summary = $result->getAuditSummary();
        self::assertSame(
            ['file' => 'system.log', 'matches' => 1, 'lines_scanned' => 5, 'truncated' => false],
            $summary
        );
        self::assertStringNotContainsString('payment', json_encode($summary) ?: '');
    }

    public function testRejectsATooShortNeedle(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute(['file' => 'system.log', 'contains' => 'a']);
    }

    public function testRejectsAMissingNeedle(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute(['file' => 'system.log']);
    }

    public function testRejectsAFileOutsideTheLogDirectory(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute(['file' => '../../app/etc/env.php', 'contains' => 'crypt']);
    }

    /**
     * Declares the tool's whole response contract, so every assertion below is
     * checked against it rather than poking at an untyped array.
     *
     * @phpstan-param array<string, mixed> $arguments
     * @param array $arguments
     * @phpstan-return array{
     *     file: string,
     *     contains: string,
     *     case_sensitive: bool,
     *     max_matches: int,
     *     context_lines: int,
     *     match_count: int,
     *     lines_scanned: int,
     *     bytes_scanned: int,
     *     file_size_bytes: int,
     *     scan_window: array{start_byte: int, end_byte: int, covers_whole_file: bool},
     *     truncated: bool,
     *     stop_reason: string,
     *     long_lines_truncated: int,
     *     caps: array{
     *         max_matches: int,
     *         max_context_lines: int,
     *         max_line_length: int,
     *         max_output_bytes: int,
     *         max_scan_bytes: int
     *     },
     *     matches: list<array{line: int, text: string, match: bool}>
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
         * @phpstan-var array{
         *     file: string,
         *     contains: string,
         *     case_sensitive: bool,
         *     max_matches: int,
         *     context_lines: int,
         *     match_count: int,
         *     lines_scanned: int,
         *     bytes_scanned: int,
         *     file_size_bytes: int,
         *     scan_window: array{start_byte: int, end_byte: int, covers_whole_file: bool},
         *     truncated: bool,
         *     stop_reason: string,
         *     long_lines_truncated: int,
         *     caps: array{
         *         max_matches: int,
         *         max_context_lines: int,
         *         max_line_length: int,
         *         max_output_bytes: int,
         *         max_scan_bytes: int
         *     },
         *     matches: list<array{line: int, text: string, match: bool}>
         * } $decoded
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

    /**
     * @return ModuleConfig
     */
    private function allowAllFixtures(): ModuleConfig
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getAllowedLogFiles')->willReturn(['system.log', 'exception.log']);

        return $config;
    }
}
