<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool\Bulk;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Tool\Bulk\BulkToolAdapter;
use Magebit\Mcp\Model\Tool\Bulk\JobToolGate;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class JobToolGateTest extends TestCase
{
    /**
     * @dataProvider jobToolNames
     * @param string $name
     * @param bool $expected
     */
    public function testIsJobToolChecksTheCanonicalPrefix(string $name, bool $expected): void
    {
        self::assertSame($expected, $this->gate([])->isJobTool($name));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function jobToolNames(): array
    {
        return [
            'get' => ['system.job.get', true],
            'list' => ['system.job.list', true],
            'cancel' => ['system.job.cancel', true],
            'resume' => ['system.job.resume', true],
            'wire form is not canonical' => ['system_job_get', false],
            'prefix without dot' => ['system.jobs.get', false],
            'bare segment' => ['system.job', false],
            'other tool' => ['catalog.product.get', false],
        ];
    }

    public function testWildcardGrantAllowsJobTools(): void
    {
        self::assertTrue($this->gate([])->allowedForScopes(null));
    }

    public function testLiteralGrantWithAsyncCapableBulkToolAllowsJobTools(): void
    {
        $gate = $this->gate([
            'system.store.list' => $this->createMock(ToolInterface::class),
            'catalog.product.bulk_update' => $this->adapter(true),
        ]);

        self::assertTrue($gate->allowedForScopes(['system.store.list', 'catalog.product.bulk_update']));
    }

    public function testLiteralGrantWithSyncOnlyBulkToolDeniesJobTools(): void
    {
        $gate = $this->gate(['system.config.set' => $this->adapter(false)]);

        self::assertFalse($gate->allowedForScopes(['system.config.set']));
    }

    public function testLiteralGrantWithoutBulkToolDeniesJobTools(): void
    {
        $gate = $this->gate([
            'system.store.list' => $this->createMock(ToolInterface::class),
            'catalog.product.bulk_update' => $this->adapter(true),
        ]);

        self::assertFalse($gate->allowedForScopes(['system.store.list']));
    }

    public function testUnregisteredScopedNameIsIgnored(): void
    {
        $gate = $this->gate(['catalog.product.bulk_update' => $this->adapter(true)]);

        self::assertFalse($gate->allowedForScopes(['catalog.product.bulk_removed']));
    }

    public function testEmptyLiteralGrantDeniesJobTools(): void
    {
        self::assertFalse($this->gate(['catalog.product.bulk_update' => $this->adapter(true)])->allowedForScopes([]));
    }

    public function testRoleWithTheJobToolResourceIsAllowed(): void
    {
        $gate = $this->gate([], ['Magebit_Mcp::tools']);

        self::assertTrue($gate->isRoleAllowed($this->createMock(User::class), $this->tool('system.job.get')));
    }

    public function testRoleWithOnlyAnAsyncBulkToolResourceIsAllowedTheJobTools(): void
    {
        $gate = $this->gate(
            ['tax.rate.bulk_import' => $this->adapter(true, 'Magento_Tax::manage_tax')],
            ['Magento_Tax::manage_tax']
        );
        $admin = $this->createMock(User::class);

        foreach (['system.job.get', 'system.job.list', 'system.job.cancel', 'system.job.resume'] as $name) {
            self::assertTrue($gate->isRoleAllowed($admin, $this->tool($name)), $name);
        }
    }

    public function testAsyncBulkToolResourceDoesNotOpenOtherTools(): void
    {
        $gate = $this->gate(
            ['tax.rate.bulk_import' => $this->adapter(true, 'Magento_Tax::manage_tax')],
            ['Magento_Tax::manage_tax']
        );

        self::assertFalse(
            $gate->isRoleAllowed($this->createMock(User::class), $this->tool('system.store.list'))
        );
    }

    public function testRoleWithOnlyASyncOnlyBulkToolResourceIsRefusedTheJobTools(): void
    {
        $gate = $this->gate(
            ['system.config.set' => $this->adapter(false, 'Magebit_Mcp::mcp_tool_system_config_set_x')],
            ['Magebit_Mcp::mcp_tool_system_config_set_x']
        );

        self::assertFalse($gate->isRoleAllowed($this->createMock(User::class), $this->tool('system.job.get')));
    }

    public function testRoleWithNeitherResourceIsRefusedTheJobTools(): void
    {
        $gate = $this->gate(['tax.rate.bulk_import' => $this->adapter(true, 'Magento_Tax::manage_tax')], []);

        self::assertFalse($gate->isRoleAllowed($this->createMock(User::class), $this->tool('system.job.get')));
    }

    public function testAsyncToolResourcesAreCollectedOncePerInstance(): void
    {
        $tools = ['tax.rate.bulk_import' => $this->adapter(true, 'Magento_Tax::manage_tax')];
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects(self::once())->method('all')->willReturn($tools);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturnCallback(
            static fn (User $_u, string $resource): bool => $resource === 'Magento_Tax::manage_tax'
        );
        $gate = new JobToolGate($registry, $acl);
        $admin = $this->createMock(User::class);

        self::assertTrue($gate->isRoleAllowed($admin, $this->tool('system.job.get')));
        self::assertTrue($gate->isRoleAllowed($admin, $this->tool('system.job.list')));
    }

    /**
     * @param array<string, ToolInterface> $tools
     * @param list<string> $allowed Resources the role allows.
     * @return JobToolGate
     */
    private function gate(array $tools, array $allowed = []): JobToolGate
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($tools);
        $registry->method('has')->willReturnCallback(static fn (string $name): bool => isset($tools[$name]));
        $registry->method('get')->willReturnCallback(static fn (string $name): ToolInterface => $tools[$name]);
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturnCallback(
            static fn (User $_u, string $resource): bool => in_array($resource, $allowed, true)
        );
        return new JobToolGate($registry, $acl);
    }

    /**
     * @param string $name
     * @return ToolInterface
     */
    private function tool(string $name): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getAclResource')->willReturn(
            str_starts_with($name, 'system.job.') ? 'Magebit_Mcp::tools' : 'Magebit_Mcp::mcp_tool_system_store_list'
        );
        return $tool;
    }

    /**
     * @param bool $asyncCapable
     * @param string $aclResource
     * @return BulkToolAdapter
     */
    private function adapter(bool $asyncCapable, string $aclResource = 'Magebit_Mcp::tools'): BulkToolAdapter
    {
        $adapter = $this->createMock(BulkToolAdapter::class);
        $adapter->method('isAsyncCapable')->willReturn($asyncCapable);
        $adapter->method('getAclResource')->willReturn($aclResource);
        return $adapter;
    }
}
