<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Tool\System\Cache;

use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Tool\System\Cache\CacheList;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CacheListTest extends TestCase
{
    /**
     * @phpstan-var TypeListInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TypeListInterface&MockObject $typeList;

    private CacheList $tool;

    protected function setUp(): void
    {
        $this->typeList = $this->createMock(TypeListInterface::class);
        $this->typeList->method('getTypes')->willReturn([
            'config' => new DataObject([
                'id' => 'config',
                'cache_type' => 'Configuration',
                'description' => 'Various XML configurations',
                'tags' => 'CONFIG',
                'status' => 1,
            ]),
            'layout' => new DataObject([
                'id' => 'layout',
                'cache_type' => 'Layouts',
                'description' => 'Layout building instructions',
                'tags' => 'LAYOUT_GENERAL_CACHE_TAG',
                'status' => 0,
            ]),
        ]);
        $this->typeList->method('getInvalidated')->willReturn([
            'config' => new DataObject(['id' => 'config']),
        ]);
        $this->tool = new CacheList($this->typeList);
    }

    public function testMetadata(): void
    {
        self::assertSame('system.cache.list', $this->tool->getName());
        self::assertSame('Magebit_Mcp::tool_system_cache_list', $this->tool->getAclResource());
        self::assertSame('Magento_Backend::cache', $this->tool->getUnderlyingAclResource());
        self::assertSame(WriteMode::READ, $this->tool->getWriteMode());
        self::assertFalse($this->tool->getConfirmationRequired());
    }

    public function testListsEveryTypeWithStatusAndInvalidatedFlag(): void
    {
        $rows = $this->decodeRows($this->tool->execute([])->getContent());
        self::assertCount(2, $rows);

        self::assertSame('config', $rows[0]['id']);
        self::assertSame('enabled', $rows[0]['status']);
        self::assertSame(true, $rows[0]['invalidated']);

        self::assertSame('layout', $rows[1]['id']);
        self::assertSame('disabled', $rows[1]['status']);
        self::assertSame(false, $rows[1]['invalidated']);

        $audit = $this->tool->execute([])->getAuditSummary();
        self::assertSame(2, $audit['cache_type_count']);
        self::assertSame(1, $audit['invalidated_count']);
    }

    public function testFilterRestrictsOutput(): void
    {
        $rows = $this->decodeRows(
            $this->tool->execute(['cache_type' => ['layout']])->getContent()
        );
        self::assertCount(1, $rows);
        self::assertSame('layout', $rows[0]['id']);
    }

    public function testEmptyFilterArrayRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tool->execute(['cache_type' => []]);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @return array<int, array<string, mixed>>
     */
    private function decodeRows(array $content): array
    {
        self::assertArrayHasKey(0, $content);
        $text = $content[0]['text'] ?? null;
        self::assertIsString($text);
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('cache_types', $decoded);
        $rows = $decoded['cache_types'];
        self::assertIsArray($rows);
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }
}
