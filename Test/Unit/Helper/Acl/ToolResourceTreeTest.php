<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Helper\Acl;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ToolResourceTreeTest extends TestCase
{
    /**
     * @phpstan-var ProviderInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ProviderInterface&MockObject $resourceProvider;

    /**
     * @phpstan-var AclChecker&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AclChecker&MockObject $aclChecker;

    /**
     * @phpstan-var AdminUserLookup&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdminUserLookup&MockObject $adminUserLookup;

    /**
     * @phpstan-var ToolRegistryInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ToolRegistryInterface&MockObject $toolRegistry;

    private ToolResourceTree $helper;

    protected function setUp(): void
    {
        $this->resourceProvider = $this->createMock(ProviderInterface::class);
        $this->aclChecker = $this->createMock(AclChecker::class);
        $this->adminUserLookup = $this->createMock(AdminUserLookup::class);
        $this->toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $this->toolRegistry->method('all')->willReturn([
            $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list'),
            $this->makeTool('system.store.info', 'Magebit_Mcp::tool_system_store_info'),
        ]);

        $this->helper = new ToolResourceTree(
            $this->resourceProvider,
            $this->aclChecker,
            $this->adminUserLookup,
            $this->toolRegistry
        );
    }

    private function makeTool(string $name, string $aclResource): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getAclResource')->willReturn($aclResource);
        return $tool;
    }

    public function testFullyEnabledTreeForAdminWithAllPermissions(): void
    {
        $this->resourceProvider->method('getAclResources')->willReturn($this->fixtureResources());
        $this->primeAdmin(42, 'role-1');
        $this->aclChecker->method('isAllowedForRole')->willReturn(true);

        $tree = $this->helper->build(42);

        $this->assertCount(1, $tree);
        $group = $tree[0];
        $this->assertIsArray($group);
        $this->assertSame('mcp_group_system', $group['id']);
        $this->assertIsArray($group['state']);
        $this->assertFalse($group['state']['disabled'], 'group is selectable when at least one child is enabled');
        $this->assertTrue($group['state']['opened'], 'open the group when at least one child is enabled');

        $children = $group['children'];
        $this->assertIsArray($children);
        $this->assertCount(2, $children);
        foreach ($children as $leaf) {
            $this->assertIsArray($leaf);
            $this->assertIsArray($leaf['state']);
            $this->assertFalse($leaf['state']['disabled']);
            $this->assertInstanceOf(\stdClass::class, $leaf['a_attr']);
        }
    }

    public function testDisablesNodesNotAllowedByRole(): void
    {
        $this->resourceProvider->method('getAclResources')->willReturn($this->fixtureResources());
        $this->primeAdmin(42, 'role-1');
        $this->aclChecker->method('isAllowedForRole')->willReturnCallback(
            static fn (string $role, string $resource): bool
                => $resource === 'Magebit_Mcp::tool_system_store_list'
        );

        $tree = $this->helper->build(42);
        $byId = $this->indexLeavesById($tree);

        $stateAllowed = $byId['Magebit_Mcp::tool_system_store_list']['state'];
        $stateBlocked = $byId['Magebit_Mcp::tool_system_store_info']['state'];
        $this->assertIsArray($stateAllowed);
        $this->assertIsArray($stateBlocked);
        $this->assertFalse($stateAllowed['disabled']);
        $this->assertTrue($stateBlocked['disabled']);
        $blockedAttr = $byId['Magebit_Mcp::tool_system_store_info']['a_attr'];
        $this->assertIsArray($blockedAttr);
        $this->assertArrayHasKey('title', $blockedAttr);
    }

    public function testNoAdminUserDisablesEverything(): void
    {
        $this->resourceProvider->method('getAclResources')->willReturn($this->fixtureResources());
        $this->adminUserLookup->expects($this->never())->method('getById');
        $this->aclChecker->expects($this->never())->method('isAllowedForRole');

        $tree = $this->helper->build(null);
        $byId = $this->indexLeavesById($tree);

        $this->assertCount(2, $byId);
        foreach ($byId as $leaf) {
            $this->assertIsArray($leaf['state']);
            $this->assertTrue($leaf['state']['disabled']);
        }
    }

    public function testFiltersToToolsSubtreeOnly(): void
    {
        $this->resourceProvider->method('getAclResources')->willReturn($this->fixtureResources());
        $this->primeAdmin(7, 'role-1');
        $this->aclChecker->method('isAllowedForRole')->willReturn(false);

        $tree = $this->helper->build(7);
        $leafIds = array_keys($this->indexLeavesById($tree));
        sort($leafIds);

        $this->assertSame(
            ['Magebit_Mcp::tool_system_store_info', 'Magebit_Mcp::tool_system_store_list'],
            $leafIds
        );
    }

    private function primeAdmin(int $id, string $aclRole): void
    {
        $user = $this->createMock(User::class);
        $user->method('getAclRole')->willReturn($aclRole);
        $this->adminUserLookup->method('getById')->with($id)->willReturn($user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fixtureResources(): array
    {
        return [[
            'id' => 'Magento_Backend::admin',
            'title' => 'Admin',
            'children' => [[
                'id' => 'Magento_Backend::system',
                'title' => 'System',
                'children' => [[
                    'id' => 'Magebit_Mcp::mcp',
                    'title' => 'MCP',
                    'children' => [[
                        'id' => 'Magebit_Mcp::tools',
                        'title' => 'MCP Tools',
                        'children' => [
                            [
                                'id' => 'Magebit_Mcp::tool_system_store_list',
                                'title' => 'Tool: List Stores',
                            ],
                            [
                                'id' => 'Magebit_Mcp::tool_system_store_info',
                                'title' => 'Tool: Get Store',
                            ],
                        ],
                    ]],
                ]],
            ]],
        ]];
    }

    /**
     * Recursively index leaf nodes by id, ignoring synthetic group buckets.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, array<string, mixed>>
     */
    private function indexLeavesById(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            $children = $node['children'] ?? null;
            if (is_array($children) && $children !== []) {
                $out += $this->indexLeavesById($children);
                continue;
            }
            $out[$id] = $node;
        }
        return $out;
    }
}
