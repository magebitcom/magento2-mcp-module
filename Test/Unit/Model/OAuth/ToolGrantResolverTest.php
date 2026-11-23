<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Api\AvailabilityAwareInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Helper\Acl\ToolResourceTree;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\OAuth\Client;
use Magebit\Mcp\Model\OAuth\ClientRepository;
use Magebit\Mcp\Model\OAuth\ConsentOfferedTools;
use Magebit\Mcp\Model\OAuth\ConsentParamsResolver;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ToolDomain;
use Magebit\Mcp\ViewModel\Oauth\Authorize\ConsentViewModel;
use Magento\Backend\Model\Auth;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\User\Model\ResourceModel\User\CollectionFactory;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class ToolGrantResolverTest extends TestCase
{
    public function testIntersectClientCapTickedAndAclRole(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'catalog.product.get' => [WriteMode::READ, 'Mod::product_get'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
        ]);

        $admin = $this->createMock(User::class);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturnMap([
            [$admin, 'Mod::store_list', true],
            [$admin, 'Mod::product_get', false],
            [$admin, 'Mod::order_cancel', true],
        ]);

        $resolver = new ToolGrantResolver($registry, $acl, $this->createMock(ConsentOfferedTools::class));

        $granted = $resolver->intersect(
            ['system.store.list', 'catalog.product.get', 'sales.order.cancel'],
            ['system.store.list', 'catalog.product.get'],
            $admin
        );

        // catalog.product.get is in client cap + ticked but admin's role denies it → dropped.
        self::assertSame(['system.store.list'], $granted);
    }

    public function testEmptyInputsProduceEmptyGrant(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
        ]);
        $resolver = new ToolGrantResolver(
            $registry,
            $this->createMock(AclChecker::class),
            $this->createMock(ConsentOfferedTools::class)
        );
        $admin = $this->createMock(User::class);

        self::assertSame([], $resolver->intersect([], ['system.store.list'], $admin));
        self::assertSame([], $resolver->intersect(['system.store.list'], [], $admin));
    }

    /**
     * The record kept on the auth code: what the consent tree offered this admin, inside the client's cap,
     * through the same intersection the grant uses.
     */
    public function testOfferedToolsIsTheConsentTreeInsideTheClientCap(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'catalog.product.get' => [WriteMode::READ, 'Mod::product_get'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
            'cms.page.get' => [WriteMode::READ, 'Mod::page_get'],
        ]);
        $admin = $this->createMock(User::class);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturn(true);
        $offered = $this->createMock(ConsentOfferedTools::class);
        $offered->method('forAdmin')->with($admin)
            ->willReturn(['system.store.list', 'catalog.product.get', 'sales.order.cancel']);

        $resolver = new ToolGrantResolver($registry, $acl, $offered);

        self::assertSame(
            ['system.store.list', 'sales.order.cancel'],
            $resolver->offeredTools(['system.store.list', 'sales.order.cancel', 'cms.page.get'], $admin)
        );
        self::assertSame(
            ['system.store.list', 'catalog.product.get', 'sales.order.cancel'],
            $resolver->offeredTools(['*'], $admin)
        );
    }

    public function testOfferedToolsIsEmptyWhenNothingIsOffered(): void
    {
        $registry = $this->buildRegistry(['system.store.list' => [WriteMode::READ, 'Mod::store_list']]);
        $offered = $this->createMock(ConsentOfferedTools::class);
        $offered->method('forAdmin')->willReturn([]);
        $resolver = new ToolGrantResolver($registry, $this->createMock(AclChecker::class), $offered);

        self::assertSame([], $resolver->offeredTools(['*'], $this->createMock(User::class)));
    }

    public function testIsWildcardDetectsSentinel(): void
    {
        self::assertTrue(ToolGrantResolver::isWildcard(['*']));
        self::assertFalse(ToolGrantResolver::isWildcard([]));
        self::assertFalse(ToolGrantResolver::isWildcard(['system.store.list']));
        self::assertFalse(ToolGrantResolver::isWildcard(['*', 'system.store.list']));
    }

    public function testWildcardClientCapExpandsToLiveRegistry(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'catalog.product.get' => [WriteMode::READ, 'Mod::product_get'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
        ]);

        $admin = $this->createMock(User::class);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturn(true);

        $resolver = new ToolGrantResolver($registry, $acl, $this->createMock(ConsentOfferedTools::class));

        $granted = $resolver->intersect(
            ['*'],
            ['system.store.list', 'catalog.product.get', 'sales.order.cancel'],
            $admin
        );

        self::assertSame(
            ['system.store.list', 'catalog.product.get', 'sales.order.cancel'],
            $granted
        );
    }

    public function testWildcardClientCapGrantsOnlyTickedTools(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'catalog.product.get' => [WriteMode::READ, 'Mod::product_get'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
        ]);

        $admin = $this->createMock(User::class);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturn(true);

        $resolver = new ToolGrantResolver($registry, $acl, $this->createMock(ConsentOfferedTools::class));

        // Admin only ticked one tool on the consent screen — wildcard cap doesn't
        // bypass the admin's narrowing.
        $granted = $resolver->intersect(['*'], ['catalog.product.get'], $admin);

        self::assertSame(['catalog.product.get'], $granted);
    }

    public function testWildcardClientCapStillEnforcesAdminAcl(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
        ]);

        $admin = $this->createMock(User::class);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturnMap([
            [$admin, 'Mod::store_list', true],
            [$admin, 'Mod::order_cancel', false],
        ]);

        $resolver = new ToolGrantResolver($registry, $acl, $this->createMock(ConsentOfferedTools::class));

        $granted = $resolver->intersect(
            ['*'],
            ['system.store.list', 'sales.order.cancel'],
            $admin
        );

        // Wildcard doesn't bypass admin role — sales.order.cancel still gets dropped.
        self::assertSame(['system.store.list'], $granted);
    }

    public function testHasWriteToolDetectsWriteMode(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
        ]);
        $resolver = new ToolGrantResolver(
            $registry,
            $this->createMock(AclChecker::class),
            $this->createMock(ConsentOfferedTools::class)
        );

        self::assertFalse($resolver->hasWriteTool(['system.store.list']));
        self::assertTrue($resolver->hasWriteTool(['system.store.list', 'sales.order.cancel']));
        self::assertFalse($resolver->hasWriteTool([]));
    }

    public function testSummarizeScopeCombinesReadAndWrite(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
        ]);
        $resolver = new ToolGrantResolver(
            $registry,
            $this->createMock(AclChecker::class),
            $this->createMock(ConsentOfferedTools::class)
        );

        self::assertSame('mcp:read', $resolver->summarizeScope(['system.store.list']));
        self::assertSame('mcp:write', $resolver->summarizeScope(['sales.order.cancel']));
        self::assertSame('mcp:read mcp:write', $resolver->summarizeScope(
            ['system.store.list', 'sales.order.cancel']
        ));
        self::assertSame('', $resolver->summarizeScope([]));
    }

    public function testGrantsAllComparesDeduplicatedSetsAgainstTheOfferedTools(): void
    {
        $admin = $this->createMock(User::class);
        $offered = $this->createMock(ConsentOfferedTools::class);
        $offered->method('forAdmin')->with($admin)->willReturn(['system.store.list', 'catalog.product.get']);
        $resolver = new ToolGrantResolver(
            $this->buildRegistry([]),
            $this->createMock(AclChecker::class),
            $offered
        );

        self::assertTrue($resolver->grantsAllAdminAccessibleTools(
            ['catalog.product.get', 'system.store.list', 'catalog.product.get'],
            $admin
        ));
        self::assertFalse($resolver->grantsAllAdminAccessibleTools(['system.store.list'], $admin));
        self::assertFalse($resolver->grantsAllAdminAccessibleTools(
            ['system.store.list', 'catalog.product.get', 'sales.order.get'],
            $admin
        ));
    }

    public function testGrantsAllIsFalseWhenNothingIsOffered(): void
    {
        $offered = $this->createMock(ConsentOfferedTools::class);
        $offered->method('forAdmin')->willReturn([]);
        $resolver = new ToolGrantResolver(
            $this->buildRegistry([]),
            $this->createMock(AclChecker::class),
            $offered
        );

        self::assertFalse($resolver->grantsAllAdminAccessibleTools([], $this->createMock(User::class)));
    }

    /**
     * Case (a): the registry holds a tool whose optional module is missing (`isAvailable()` false).
     */
    public function testAcceptAllWithUnavailableToolStillWildcard(): void
    {
        $unavailable = $this->createMock(AvailabilityToolFixtureInterface::class);
        $this->primeTool($unavailable, 'catalog.product.get', 'Mod::product_get');
        $unavailable->method('isAvailable')->willReturn(false);

        $this->assertAcceptAllIsWildcard(
            [$this->plainTool('system.store.list', 'Mod::store_list'), $unavailable],
            $this->toolsNode([$this->leaf('Mod::store_list'), $this->leaf('Mod::product_get')]),
            []
        );
    }

    /**
     * Case (b): the role allows the tool's MCP resource but not the admin-UI resource it wraps.
     */
    public function testAcceptAllWithUnderlyingAclHiddenToolStillWildcard(): void
    {
        $hidden = $this->createMock(UnderlyingAclToolFixtureInterface::class);
        $this->primeTool($hidden, 'sales.order.cancel', 'Mod::order_cancel');
        $hidden->method('getUnderlyingAclResource')->willReturn('Magento_Sales::cancel');

        $this->assertAcceptAllIsWildcard(
            [$this->plainTool('system.store.list', 'Mod::store_list'), $hidden],
            $this->toolsNode([$this->leaf('Mod::store_list'), $this->leaf('Mod::order_cancel')]),
            ['Magento_Sales::cancel']
        );
    }

    /**
     * Case (c): two tools share one ACL resource, so the consent tree renders one leaf for both.
     */
    public function testAcceptAllWithSharedAclResourceStillWildcard(): void
    {
        $this->assertAcceptAllIsWildcard(
            [
                $this->plainTool('system.store.list', 'Mod::store'),
                $this->plainTool('system.store.info', 'Mod::store'),
                $this->plainTool('catalog.product.get', 'Mod::product_get'),
            ],
            $this->toolsNode([$this->leaf('Mod::store'), $this->leaf('Mod::product_get')]),
            []
        );
    }

    /**
     * Extra case (d): a satellite declares its tool ACL outside `Magebit_Mcp::tools`
     * (the shipped tax tools nest under their own `Magebit_McpTaxTools::mcp` node).
     */
    public function testAcceptAllWithToolAclOutsideToolsNodeStillWildcard(): void
    {
        $tree = $this->toolsNode([$this->leaf('Mod::store_list')], [[
            'id' => 'Vendor_Tax::mcp',
            'title' => 'Tax',
            'children' => [$this->leaf('Vendor_Tax::tool_tax_class_list')],
        ]]);

        $this->assertAcceptAllIsWildcard(
            [
                $this->plainTool('system.store.list', 'Mod::store_list'),
                $this->plainTool('tax.class.list', 'Vendor_Tax::tool_tax_class_list'),
            ],
            $tree,
            []
        );
    }

    /**
     * Extra case (e): the `system.job.*` tools use the tree root `Magebit_Mcp::tools` as their ACL.
     */
    public function testAcceptAllWithJobToolsOnRootResourceStillWildcard(): void
    {
        $this->assertAcceptAllIsWildcard(
            [
                $this->plainTool('system.store.list', 'Mod::store_list'),
                $this->plainTool('system.job.get', ToolResourceTree::ROOT_RESOURCE_ID),
            ],
            $this->toolsNode([$this->leaf('Mod::store_list')]),
            []
        );
    }

    /**
     * Runs the consent flow end to end on real code: the view model builds the tree for a
     * wildcard client, the admin ticks every enabled node, the submitted ACL ids are parsed
     * the way the authorize controller parses them, and the grant is intersected.
     *
     * @param array<int, ToolInterface> $tools
     * @param array<int, array<string, mixed>> $aclResources
     * @param array<int, string> $deniedResources
     */
    private function assertAcceptAllIsWildcard(array $tools, array $aclResources, array $deniedResources): void
    {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->getName()] = $tool;
        }
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($byName);
        $registry->method('has')->willReturnCallback(static fn (string $n): bool => isset($byName[$n]));

        $admin = $this->createMock(User::class);
        $admin->method('getId')->willReturn(42);
        $admin->method('getAclRole')->willReturn('role-1');

        $allowed = static fn (string $resource): bool => !in_array($resource, $deniedResources, true);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowedForRole')->willReturnCallback(
            static fn (string $role, string $resource): bool => $allowed($resource)
        );
        $acl->method('isAllowed')->willReturnCallback(
            static fn (User $user, string $resource): bool => $allowed($resource)
        );

        $lookup = $this->createMock(AdminUserLookup::class);
        $lookup->method('getById')->willReturn($admin);
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getAclResources')->willReturn($aclResources);
        $resourceTree = new ToolResourceTree($provider, $acl, $lookup, $registry, new ToolDomain());

        $resolver = $this->newResolver($registry, $acl, $resourceTree);

        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn($admin);
        $viewModel = $this->newConsentViewModel($resourceTree, $registry, $auth);

        $client = $this->createMock(Client::class);
        $client->method('getAllowedTools')->willReturn(['*']);

        $tickedAclIds = $this->collectEnabledIds($viewModel->getTreeForRender($client));
        $paramsResolver = new ConsentParamsResolver(
            $this->createMock(ClientRepository::class),
            $registry,
            $resolver,
            $lookup
        );
        $ticked = $paramsResolver->parseSubmittedToolNames($tickedAclIds);
        $granted = $resolver->intersect(['*'], $ticked, $admin);

        self::assertNotSame([], $granted, 'Ticking every offered node must grant something.');
        self::assertTrue(
            $resolver->grantsAllAdminAccessibleTools($granted, $admin),
            sprintf(
                'Accept-all consent was not recognised as the wildcard grant. Ticked ACL ids: [%s]; granted: [%s]; '
                . 'registry: [%s].',
                implode(', ', $tickedAclIds),
                implode(', ', $granted),
                implode(', ', array_keys($byName))
            )
        );
    }

    /**
     * @param ToolRegistryInterface $registry
     * @param AclChecker $acl
     * @param ToolResourceTree $resourceTree
     * @return ToolGrantResolver
     */
    private function newResolver(
        ToolRegistryInterface $registry,
        AclChecker $acl,
        ToolResourceTree $resourceTree
    ): ToolGrantResolver {
        return new ToolGrantResolver($registry, $acl, new ConsentOfferedTools($resourceTree));
    }

    /**
     * @param ToolResourceTree $resourceTree
     * @param ToolRegistryInterface $registry
     * @param Auth $auth
     * @return ConsentViewModel
     */
    private function newConsentViewModel(
        ToolResourceTree $resourceTree,
        ToolRegistryInterface $registry,
        Auth $auth
    ): ConsentViewModel {
        return new ConsentViewModel(
            new ConsentOfferedTools($resourceTree),
            $registry,
            $auth,
            new Json(),
            $this->createMock(CollectionFactory::class)
        );
    }

    /**
     * Every node a "select all" in the jstree submits: enabled, at any depth.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, string>
     */
    private function collectEnabledIds(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $state = $node['state'] ?? [];
            $disabled = is_array($state) && !empty($state['disabled']);
            $id = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            if (!$disabled && $id !== '') {
                $ids[] = $id;
            }
            $children = $node['children'] ?? null;
            if (is_array($children)) {
                /** @var array<int, array<string, mixed>> $children */
                $ids = array_merge($ids, $this->collectEnabledIds($children));
            }
        }
        return $ids;
    }

    /**
     * @param array<int, array<string, mixed>> $toolLeaves Children of `Magebit_Mcp::tools`.
     * @param array<int, array<string, mixed>> $systemSiblings Nodes next to `Magebit_Mcp::mcp`.
     * @return array<int, array<string, mixed>>
     */
    private function toolsNode(array $toolLeaves, array $systemSiblings = []): array
    {
        $mcpNode = [
            'id' => 'Magebit_Mcp::mcp',
            'title' => 'MCP',
            'children' => [[
                'id' => ToolResourceTree::ROOT_RESOURCE_ID,
                'title' => 'Tools',
                'children' => $toolLeaves,
            ]],
        ];
        return [[
            'id' => 'Magento_Backend::system',
            'title' => 'System',
            'children' => array_merge([$mcpNode], $systemSiblings),
        ]];
    }

    /**
     * @param string $id
     * @return array<string, mixed>
     */
    private function leaf(string $id): array
    {
        return ['id' => $id, 'title' => $id, 'children' => []];
    }

    /**
     * @param string $name
     * @param string $aclResource
     * @return ToolInterface
     */
    private function plainTool(string $name, string $aclResource): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $this->primeTool($tool, $name, $aclResource);
        return $tool;
    }

    /**
     * @param ToolInterface&\PHPUnit\Framework\MockObject\MockObject $tool
     * @param string $name
     * @param string $aclResource
     */
    private function primeTool(ToolInterface $tool, string $name, string $aclResource): void
    {
        $tool->method('getName')->willReturn($name);
        $tool->method('getAclResource')->willReturn($aclResource);
        $tool->method('getWriteMode')->willReturn(WriteMode::READ);
    }

    /**
     * @param array<string, array{0: WriteMode, 1: string}> $tools tool name → [write mode, ACL resource]
     */
    private function buildRegistry(array $tools): ToolRegistryInterface
    {
        $built = [];
        foreach ($tools as $name => [$mode, $aclResource]) {
            $tool = $this->createMock(ToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getWriteMode')->willReturn($mode);
            $tool->method('getAclResource')->willReturn($aclResource);
            $built[$name] = $tool;
        }
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($built);
        return $registry;
    }
}

interface AvailabilityToolFixtureInterface extends ToolInterface, AvailabilityAwareInterface
{
}

interface UnderlyingAclToolFixtureInterface extends ToolInterface, UnderlyingAclAwareInterface
{
}
