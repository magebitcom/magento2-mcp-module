<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Model\Tool;

use Magebit\Mcp\Api\AvailabilityAwareInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\ToolAvailability;
use Magento\Framework\Acl;
use Magento\Framework\Acl\Builder as AclBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ToolAvailabilityTest extends TestCase
{
    private const DECLARED = 'Magento_Cms::page';

    public function testPlainToolIsAvailable(): void
    {
        self::assertTrue($this->availability()->isAvailable($this->createMock(ToolInterface::class)));
    }

    public function testAvailabilityAwareToolDecides(): void
    {
        $off = $this->createMock(AvailabilityToolFixtureInterface::class);
        $off->method('isAvailable')->willReturn(false);
        $on = $this->createMock(AvailabilityToolFixtureInterface::class);
        $on->method('isAvailable')->willReturn(true);

        self::assertFalse($this->availability()->isAvailable($off));
        self::assertTrue($this->availability()->isAvailable($on));
    }

    public function testUndeclaredUnderlyingResourceMeansUnavailable(): void
    {
        self::assertFalse($this->availability()->isAvailable(
            $this->underlying('Magebit_McpHyvaCommerceTools::feature_unavailable')
        ));
    }

    public function testDeclaredOrNoUnderlyingResourceStaysAvailable(): void
    {
        self::assertTrue($this->availability()->isAvailable($this->underlying(self::DECLARED)));
        self::assertTrue($this->availability()->isAvailable($this->underlying(null)));
        self::assertTrue($this->availability()->isAvailable($this->underlying('')));
    }

    public function testAclThatCannotBeBuiltLeavesTheToolToTheAclGate(): void
    {
        $builder = $this->createMock(AclBuilder::class);
        $builder->method('getAcl')->willThrowException(new RuntimeException('no acl'));

        self::assertTrue((new ToolAvailability($builder))->isAvailable($this->underlying('Any::thing')));
    }

    public function testExplicitInterfaceWinsOverDeclaredUnderlyingResource(): void
    {
        $tool = $this->createMock(AvailabilityUnderlyingToolFixtureInterface::class);
        $tool->method('isAvailable')->willReturn(false);
        $tool->method('getUnderlyingAclResource')->willReturn(self::DECLARED);

        self::assertFalse($this->availability()->isAvailable($tool));
    }

    /**
     * @param string|null $resource
     * @return ToolInterface
     */
    private function underlying(?string $resource): ToolInterface
    {
        $tool = $this->createMock(UnderlyingToolFixtureInterface::class);
        $tool->method('getUnderlyingAclResource')->willReturn($resource);
        return $tool;
    }

    /**
     * @return ToolAvailability
     */
    private function availability(): ToolAvailability
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('hasResource')->willReturnCallback(
            static fn (mixed $resource): bool => $resource === self::DECLARED
        );
        $builder = $this->createMock(AclBuilder::class);
        $builder->method('getAcl')->willReturn($acl);
        return new ToolAvailability($builder);
    }
}

interface AvailabilityToolFixtureInterface extends ToolInterface, AvailabilityAwareInterface
{
}

interface UnderlyingToolFixtureInterface extends ToolInterface, UnderlyingAclAwareInterface
{
}

interface AvailabilityUnderlyingToolFixtureInterface extends
    ToolInterface,
    AvailabilityAwareInterface,
    UnderlyingAclAwareInterface
{
}
