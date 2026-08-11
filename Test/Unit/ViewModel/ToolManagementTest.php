<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\ViewModel;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\ViewModel\ToolManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ToolManagementTest extends TestCase
{
    /**
     * @return ToolInterface&MockObject
     */
    private function tool(string $name, string $acl, WriteMode $mode): ToolInterface&MockObject
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getTitle')->willReturn(ucfirst($name));
        $tool->method('getDescription')->willReturn('desc');
        $tool->method('getAclResource')->willReturn($acl);
        $tool->method('getWriteMode')->willReturn($mode);
        $tool->method('getConfirmationRequired')->willReturn($mode === WriteMode::WRITE);
        return $tool;
    }

    public function testGroupsByModuleWithCoreFirstAndFlagsDisabled(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn([
            'cms.page.update' => $this->tool(
                'cms.page.update',
                'Magebit_McpCmsTools::tool_cms_page_update',
                WriteMode::WRITE
            ),
            'system.whoami' => $this->tool(
                'system.whoami',
                'Magebit_Mcp::tool_system_whoami',
                WriteMode::READ
            ),
        ]);
        $disabled = $this->createMock(DisabledTools::class);
        $disabled->method('getAll')->willReturn(['cms.page.update']);

        $modules = (new ToolManagement($registry, $disabled))->getModules();

        $this->assertSame(['Magebit_Mcp', 'Magebit_McpCmsTools'], array_keys($modules));
        $cmsRow = $modules['Magebit_McpCmsTools'][0];
        $this->assertSame('cms.page.update', $cmsRow['name']);
        $this->assertTrue($cmsRow['write']);
        $this->assertTrue($cmsRow['disabled']);
        $this->assertFalse($modules['Magebit_Mcp'][0]['disabled']);
    }
}
