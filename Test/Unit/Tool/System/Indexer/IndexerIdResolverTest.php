<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Indexer;

use Magebit\Mcp\Tool\System\Indexer\IndexerIdResolver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\ConfigInterface;
use PHPUnit\Framework\TestCase;

class IndexerIdResolverTest extends TestCase
{
    private IndexerIdResolver $resolver;

    protected function setUp(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getIndexers')->willReturn([
            'catalog_product_price' => ['indexer_id' => 'catalog_product_price'],
            'catalogsearch_fulltext' => ['indexer_id' => 'catalogsearch_fulltext'],
            'cataloginventory_stock' => ['indexer_id' => 'cataloginventory_stock'],
        ]);
        $this->resolver = new IndexerIdResolver($config);
    }

    public function testAllReturnsEveryAvailableIndexer(): void
    {
        self::assertSame(
            ['catalog_product_price', 'catalogsearch_fulltext', 'cataloginventory_stock'],
            $this->resolver->resolve(['all' => true])
        );
    }

    public function testExplicitIdsPassThrough(): void
    {
        self::assertSame(
            ['catalogsearch_fulltext'],
            $this->resolver->resolve(['indexer_id' => ['catalogsearch_fulltext']])
        );
    }

    public function testBothArgumentsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->resolver->resolve(['indexer_id' => ['catalog_product_price'], 'all' => true]);
    }

    public function testNeitherArgumentRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->resolver->resolve([]);
    }

    public function testUnknownIdsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Unknown indexer id\(s\): typo_idx/');
        $this->resolver->resolve(['indexer_id' => ['catalog_product_price', 'typo_idx']]);
    }
}
