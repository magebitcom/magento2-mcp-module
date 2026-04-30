<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\OAuth;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\OAuth\ToolGrantResolver;
use Magebit\Mcp\Model\Tool\WriteMode;
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

        $resolver = new ToolGrantResolver($registry, $acl);

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
        $resolver = new ToolGrantResolver($registry, $this->createMock(AclChecker::class));
        $admin = $this->createMock(User::class);

        self::assertSame([], $resolver->intersect([], ['system.store.list'], $admin));
        self::assertSame([], $resolver->intersect(['system.store.list'], [], $admin));
    }

    public function testHasWriteToolDetectsWriteMode(): void
    {
        $registry = $this->buildRegistry([
            'system.store.list' => [WriteMode::READ, 'Mod::store_list'],
            'sales.order.cancel' => [WriteMode::WRITE, 'Mod::order_cancel'],
        ]);
        $resolver = new ToolGrantResolver($registry, $this->createMock(AclChecker::class));

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
        $resolver = new ToolGrantResolver($registry, $this->createMock(AclChecker::class));

        self::assertSame('mcp:read', $resolver->summarizeScope(['system.store.list']));
        self::assertSame('mcp:write', $resolver->summarizeScope(['sales.order.cancel']));
        self::assertSame('mcp:read mcp:write', $resolver->summarizeScope(
            ['system.store.list', 'sales.order.cancel']
        ));
        self::assertSame('', $resolver->summarizeScope([]));
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
