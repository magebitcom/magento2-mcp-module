<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Indexer;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Tool\System\Indexer\IndexerIdResolver;
use Magebit\Mcp\Tool\System\Indexer\Reindex;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReindexTest extends TestCase
{
    /**
     * @phpstan-var IndexerRegistry&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private IndexerRegistry&MockObject $registry;

    /**
     * @phpstan-var IndexerIdResolver&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private IndexerIdResolver&MockObject $resolver;

    /**
     * @phpstan-var LoggerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private LoggerInterface&MockObject $logger;

    private Reindex $tool;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(IndexerRegistry::class);
        $this->resolver = $this->createMock(IndexerIdResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tool = new Reindex($this->registry, $this->resolver, $this->logger);
    }

    public function testHappyPathReportsSuccessForEachIndexer(): void
    {
        $this->resolver->method('resolve')->willReturn(['idx_a', 'idx_b']);
        $this->registry->method('get')->willReturnMap([
            ['idx_a', $this->indexerExpectingReindex(false)],
            ['idx_b', $this->indexerExpectingReindex(false)],
        ]);

        $result = $this->tool->execute(['all' => true]);
        $rows = $this->resultRows($result);

        self::assertCount(2, $rows);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame(true, $first['success'] ?? null);
        $second = $rows[1];
        self::assertIsArray($second);
        self::assertSame(true, $second['success'] ?? null);

        self::assertFalse($result->isError());
        self::assertSame(2, $result->getAuditSummary()['success_count'] ?? null);
        self::assertSame(0, $result->getAuditSummary()['failure_count'] ?? null);
    }

    public function testPartialFailureCapturedPerIndexer(): void
    {
        $this->resolver->method('resolve')->willReturn(['idx_a', 'idx_b']);
        $this->registry->method('get')->willReturnMap([
            ['idx_a', $this->indexerExpectingReindex(false)],
            ['idx_b', $this->indexerExpectingReindex(true)],
        ]);
        $this->logger->expects(self::once())->method('error');

        $result = $this->tool->execute(['all' => true]);
        $rows = $this->resultRows($result);

        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame(true, $first['success'] ?? null);
        self::assertArrayHasKey('error', $first);
        self::assertNull($first['error']);

        $second = $rows[1];
        self::assertIsArray($second);
        self::assertSame(false, $second['success'] ?? null);
        self::assertSame('boom', $second['error'] ?? null);

        self::assertTrue($result->isError());
        self::assertSame(1, $result->getAuditSummary()['success_count'] ?? null);
        self::assertSame(1, $result->getAuditSummary()['failure_count'] ?? null);
    }

    /**
     * @return array<int, mixed>
     */
    private function resultRows(ToolResultInterface $result): array
    {
        $content = $result->getContent();
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        $rows = $decoded['results'] ?? null;
        self::assertIsArray($rows);
        return array_values($rows);
    }

    private function indexerExpectingReindex(bool $shouldThrow): IndexerInterface
    {
        $indexer = $this->createMock(IndexerInterface::class);
        if ($shouldThrow) {
            $indexer->method('reindexAll')->willThrowException(new RuntimeException('boom'));
        } else {
            $indexer->expects(self::once())->method('reindexAll');
        }
        return $indexer;
    }
}
