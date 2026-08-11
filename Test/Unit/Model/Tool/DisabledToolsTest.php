<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool;

use Magebit\Mcp\Model\Tool\DisabledTools;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DisabledToolsTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ScopeConfigInterface&MockObject $scopeConfig;

    /**
     * @var WriterInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private WriterInterface&MockObject $writer;

    /**
     * @var TypeListInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TypeListInterface&MockObject $cacheTypeList;

    private DisabledTools $disabledTools;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->writer = $this->createMock(WriterInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->disabledTools = new DisabledTools($this->scopeConfig, $this->writer, $this->cacheTypeList);
    }

    public function testGetAllParsesNewlineSeparatedAndSkipsBlanks(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('magebit_mcp/tools/disabled')
            ->willReturn("cms.page.update\n\n  sales.order.cancel  \n");
        $this->assertSame(['cms.page.update', 'sales.order.cancel'], $this->disabledTools->getAll());
    }

    public function testGetAllEmptyWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame([], $this->disabledTools->getAll());
    }

    public function testIsDisabled(): void
    {
        $this->scopeConfig->method('getValue')->willReturn("cms.page.update");
        $this->assertTrue($this->disabledTools->isDisabled('cms.page.update'));
        $this->assertFalse($this->disabledTools->isDisabled('cms.page.get'));
    }

    public function testSetDisabledAddsSavesAndCleansConfigCache(): void
    {
        $this->scopeConfig->method('getValue')->willReturn("cms.page.update");
        $this->writer->expects($this->once())
            ->method('save')
            ->with('magebit_mcp/tools/disabled', "cms.page.update\nsales.order.cancel");
        $this->cacheTypeList->expects($this->once())->method('cleanType')->with('config');

        $this->disabledTools->setDisabled('sales.order.cancel', true);
    }

    public function testSetDisabledIsIdempotentOnDuplicate(): void
    {
        $this->scopeConfig->method('getValue')->willReturn("cms.page.update");
        $this->writer->expects($this->never())->method('save');
        $this->disabledTools->setDisabled('cms.page.update', true);
    }

    public function testSetEnabledRemovesAndSaves(): void
    {
        $this->scopeConfig->method('getValue')->willReturn("cms.page.update\nsales.order.cancel");
        $this->writer->expects($this->once())
            ->method('save')
            ->with('magebit_mcp/tools/disabled', 'sales.order.cancel');
        $this->disabledTools->setDisabled('cms.page.update', false);
    }

    public function testGetAllMemoizesAcrossRepeatedCalls(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('magebit_mcp/tools/disabled')
            ->willReturn("cms.page.update\nsales.order.cancel");

        $this->assertTrue($this->disabledTools->isDisabled('cms.page.update'));
        $this->assertTrue($this->disabledTools->isDisabled('sales.order.cancel'));
        $this->assertFalse($this->disabledTools->isDisabled('catalog.product.delete'));
        $this->assertSame(['cms.page.update', 'sales.order.cancel'], $this->disabledTools->getAll());
    }

    public function testSetDisabledTwiceInOneRequestSeesFirstChange(): void
    {
        // Mock always returns the original stale value, exactly as store
        // config would mid-request since the writer doesn't mutate it.
        $this->scopeConfig->method('getValue')->willReturn('cms.page.update');

        $savedValues = [];
        $this->writer->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(function (string $path, string $value) use (&$savedValues): void {
                $savedValues[] = $value;
            });
        $this->cacheTypeList->expects($this->exactly(2))->method('cleanType')->with('config');

        $this->disabledTools->setDisabled('sales.order.cancel', true);
        $this->disabledTools->setDisabled('catalog.product.delete', true);

        $this->assertSame(
            ['cms.page.update', 'sales.order.cancel', 'catalog.product.delete'],
            explode("\n", $savedValues[1])
        );
    }
}
