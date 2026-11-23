<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\OAuth\ConsentOfferedTools;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class ConsentOfferedToolsTest extends TestCase
{
    public function testForAdminReturnsEnabledToolNodesOnceInTreeOrder(): void
    {
        $tree = $this->createMock(ToolResourceTree::class);
        $tree->method('build')->with(42)->willReturn([
            [
                'id' => 'mcp_group_system',
                'state' => ['disabled' => false],
                'children' => [
                    ['id' => 'Mod::store_list', 'state' => ['disabled' => false]],
                    ['id' => 'Mod::store_info', 'state' => ['disabled' => true]],
                ],
            ],
            [
                'id' => 'Vendor_Hyva::tools',
                'state' => ['disabled' => false],
                'children' => [
                    ['id' => 'Mod::cms_page_list', 'state' => ['disabled' => false]],
                    ['id' => 'Mod::store_list', 'state' => ['disabled' => false]],
                ],
            ],
        ]);
        $tree->method('indexToolsByAclResource')->willReturn([
            'Mod::store_list' => 'system.store.list',
            'Mod::store_info' => 'system.store.info',
            'Mod::cms_page_list' => 'hyva.cms.page.list',
        ]);

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(42);

        self::assertSame(
            ['system.store.list', 'hyva.cms.page.list'],
            (new ConsentOfferedTools($tree))->forAdmin($admin)
        );
    }

    public function testForAdminWithoutIdOffersNothing(): void
    {
        $tree = $this->createMock(ToolResourceTree::class);
        $tree->expects(self::never())->method('build');

        self::assertSame([], (new ConsentOfferedTools($tree))->forAdmin($this->createMock(User::class)));
    }

    public function testTreeIsTheResourceTreeForTheAdmin(): void
    {
        $built = [['id' => 'mcp_group_system', 'children' => []]];
        $tree = $this->createMock(ToolResourceTree::class);
        $tree->method('build')->with(7)->willReturn($built);

        self::assertSame($built, (new ConsentOfferedTools($tree))->tree(7));
    }
}
