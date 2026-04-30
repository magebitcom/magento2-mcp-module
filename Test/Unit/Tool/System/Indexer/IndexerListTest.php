<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Indexer;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Tool\System\Indexer\IndexerList;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndexerListTest extends TestCase
{
    /**
     * @phpstan-var ConfigInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ConfigInterface&MockObject $config;

    /**
     * @phpstan-var IndexerRegistry&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private IndexerRegistry&MockObject $registry;

    private IndexerList $tool;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getIndexers')->willReturn([
            'catalog_product_price' => ['indexer_id' => 'catalog_product_price'],
            'cataloginventory_stock' => ['indexer_id' => 'cataloginventory_stock'],
        ]);
        $this->registry = $this->createMock(IndexerRegistry::class);
        $this->registry->method('get')->willReturnMap([
            ['catalog_product_price', $this->stubIndexer(
                'catalog_product_price',
                'Product Price',
                StateInterface::STATUS_VALID,
                false,
                '2026-04-25 10:00:00'
            )],
            ['cataloginventory_stock', $this->stubIndexer(
                'cataloginventory_stock',
                'Stock',
                StateInterface::STATUS_INVALID,
                true,
                '2026-04-25 09:30:00'
            )],
        ]);
        $this->tool = new IndexerList($this->config, $this->registry);
    }

    public function testListsEveryIndexerWithStatusAndMode(): void
    {
        $rows = $this->indexerRows($this->tool->execute([]));
        self::assertCount(2, $rows);

        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('catalog_product_price', $first['id'] ?? null);
        self::assertSame('valid', $first['status'] ?? null);
        self::assertSame('realtime', $first['mode'] ?? null);

        $second = $rows[1];
        self::assertIsArray($second);
        self::assertSame('cataloginventory_stock', $second['id'] ?? null);
        self::assertSame('invalid', $second['status'] ?? null);
        self::assertSame('scheduled', $second['mode'] ?? null);
    }

    public function testFilterRestrictsOutput(): void
    {
        $rows = $this->indexerRows(
            $this->tool->execute(['indexer_id' => ['catalog_product_price']])
        );
        self::assertCount(1, $rows);
        $first = $rows[0];
        self::assertIsArray($first);
        self::assertSame('catalog_product_price', $first['id'] ?? null);
    }

    /**
     * @return array<int, mixed>
     */
    private function indexerRows(ToolResultInterface $result): array
    {
        $content = $result->getContent();
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        $rows = $decoded['indexers'] ?? null;
        self::assertIsArray($rows);
        return array_values($rows);
    }

    private function stubIndexer(
        string $id,
        string $title,
        string $status,
        bool $scheduled,
        string $updated
    ): IndexerInterface {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('getTitle')->willReturn($title);
        $indexer->method('getStatus')->willReturn($status);
        $indexer->method('isScheduled')->willReturn($scheduled);
        $indexer->method('getLatestUpdated')->willReturn($updated);
        return $indexer;
    }
}
