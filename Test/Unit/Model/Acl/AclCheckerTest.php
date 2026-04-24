<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Acl;

use Magebit\Mcp\Model\Acl\AclChecker;
use Magento\Framework\Acl;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AclCheckerTest extends TestCase
{
    /**
     * @var AclBuilder
     * @phpstan-var AclBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AclBuilder&MockObject $aclBuilder;

    /**
     * @var Acl
     * @phpstan-var Acl&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Acl&MockObject $acl;

    /**
     * @var AclChecker
     */
    private AclChecker $checker;

    protected function setUp(): void
    {
        $this->aclBuilder = $this->createMock(AclBuilder::class);
        $this->acl = $this->createMock(Acl::class);
        $this->aclBuilder->method('getAcl')->willReturn($this->acl);

        $this->checker = new AclChecker($this->aclBuilder);
    }

    public function testGrantsWhenLaminasAclReturnsTrue(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getAclRole')->willReturn('17');
        $this->acl->expects($this->once())
            ->method('isAllowed')
            ->with('17', 'Magebit_Mcp::tool_sales_order_get')
            ->willReturn(true);

        $this->assertTrue($this->checker->isAllowed($user, 'Magebit_Mcp::tool_sales_order_get'));
    }

    public function testDeniesWhenLaminasAclReturnsFalse(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getAclRole')->willReturn('17');
        $this->acl->method('isAllowed')->willReturn(false);

        $this->assertFalse($this->checker->isAllowed($user, 'Magebit_Mcp::tool_sales_order_get'));
    }

    public function testDeniesWhenUserHasNoAclRole(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getAclRole')->willReturn(null);
        // Should short-circuit before hitting Laminas ACL.
        $this->acl->expects($this->never())->method('isAllowed');

        $this->assertFalse($this->checker->isAllowed($user, 'Magebit_Mcp::anything'));
    }

    public function testDeniesWhenLaminasAclThrows(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getAclRole')->willReturn('17');
        $this->acl->method('isAllowed')
            ->willThrowException(new RuntimeException('unknown role or resource'));

        $this->assertFalse($this->checker->isAllowed($user, 'Magebit_Mcp::ghost_resource'));
    }
}
