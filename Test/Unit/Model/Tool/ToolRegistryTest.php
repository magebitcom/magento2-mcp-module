<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool;

use InvalidArgumentException;
use Magebit\Mcp\Api\BulkToolInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapterFactory;
use Magebit\Mcp\Model\Tool\ToolRegistry;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    public function testGetByCanonicalNameReturnsRegisteredTool(): void
    {
        $tool = $this->makeTool('system.store.list');
        $registry = new ToolRegistry(['system.store.list' => $tool]);

        self::assertSame($tool, $registry->get('system.store.list'));
        self::assertTrue($registry->has('system.store.list'));
    }

    public function testGetCanonicalNameReturnsCanonicalForCanonicalInput(): void
    {
        $tool = $this->makeTool('system.store.list');
        $registry = new ToolRegistry(['system.store.list' => $tool]);

        self::assertSame('system.store.list', $registry->getCanonicalName('system.store.list'));
    }

    public function testGetCanonicalNameTranslatesUnderscoredWireFormat(): void
    {
        $tool = $this->makeTool('system.store.list');
        $registry = new ToolRegistry(['system.store.list' => $tool]);

        self::assertSame('system.store.list', $registry->getCanonicalName('system_store_list'));
    }

    public function testGetCanonicalNameHandlesUnderscoreInsideSegments(): void
    {
        // marketing.catalog_rule.set_active is canonical with underscores in
        // both the second and third segments. Wire form must round-trip back
        // to this exact canonical name even though the wire form is fully
        // ambiguous to a naive str_replace.
        $tool = $this->makeTool('marketing.catalog_rule.set_active');
        $registry = new ToolRegistry(['marketing.catalog_rule.set_active' => $tool]);

        self::assertSame(
            'marketing.catalog_rule.set_active',
            $registry->getCanonicalName('marketing_catalog_rule_set_active')
        );
    }

    public function testGetCanonicalNameReturnsNullForUnknownName(): void
    {
        $registry = new ToolRegistry(['system.store.list' => $this->makeTool('system.store.list')]);

        self::assertNull($registry->getCanonicalName('does.not.exist'));
        self::assertNull($registry->getCanonicalName('does_not_exist'));
    }

    public function testConstructionFailsWhenTwoCanonicalsCollideOnWireForm(): void
    {
        $a = $this->makeTool('foo.bar_baz');
        $b = $this->makeTool('foo_bar.baz');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wire-format collision');

        new ToolRegistry([
            'foo.bar_baz' => $a,
            'foo_bar.baz' => $b,
        ]);
    }

    public function testGetThrowsForUnknownCanonical(): void
    {
        $registry = new ToolRegistry(['system.store.list' => $this->makeTool('system.store.list')]);

        $this->expectException(NoSuchEntityException::class);
        $registry->get('does.not.exist');
    }

    public function testRegistryWrapsBulkEntries(): void
    {
        $bulk = $this->createMock(BulkToolInterface::class);
        $bulk->method('getName')->willReturn('catalog.product.bulk_update');
        $adapter = $this->createMock(BulkToolAdapter::class);
        $factory = $this->createMock(BulkToolAdapterFactory::class);
        $factory->expects(self::once())->method('create')->with(['tool' => $bulk])->willReturn($adapter);
        $plain = $this->makeTool('system.store.list');

        $registry = new ToolRegistry(
            ['system.store.list' => $plain, 'catalog.product.bulk_update' => $bulk],
            $factory
        );

        self::assertSame($adapter, $registry->get('catalog.product.bulk_update'));
        self::assertSame($plain, $registry->get('system.store.list'));
        self::assertTrue($registry->has('catalog.product.bulk_update'));
        self::assertSame(['system.store.list', 'catalog.product.bulk_update'], array_keys($registry->all()));
        self::assertSame(
            'catalog.product.bulk_update',
            $registry->getCanonicalName('catalog_product_bulk_update')
        );
    }

    public function testBulkEntryKeyMustMatchName(): void
    {
        $bulk = $this->createMock(BulkToolInterface::class);
        $bulk->method('getName')->willReturn('catalog.product.bulk_update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('registration mismatch');
        new ToolRegistry(['other.name' => $bulk], $this->createMock(BulkToolAdapterFactory::class));
    }

    public function testRegistryRejectsOtherObjects(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ToolInterface or .*BulkToolInterface/');
        /** @phpstan-ignore argument.type */
        new ToolRegistry(['a.b' => new \stdClass()]);
    }

    private function makeTool(string $name): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
        return $tool;
    }
}
