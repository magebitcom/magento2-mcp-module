<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Console\Command;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Console\Command\ValidateAclCommand;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ValidateAclCommandTest extends TestCase
{
    private const OWN_RESOURCE = 'Magebit_McpInventoryTools::tool_inventory_source_item_set';
    private const UNDERLYING_RESOURCE = 'Magento_InventoryApi::stock_source_item_assign';

    public function testPassesWhenEveryReferencedResourceIsDeclared(): void
    {
        $tester = $this->runCommand(
            [$this->makeUnderlyingAclTool(self::OWN_RESOURCE, self::UNDERLYING_RESOURCE)],
            [self::OWN_RESOURCE, self::UNDERLYING_RESOURCE]
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('OK', $tester->getDisplay());
    }

    public function testFailsWhenAToolsOwnResourceIsUndeclared(): void
    {
        $tester = $this->runCommand(
            [$this->makeTool(self::OWN_RESOURCE)],
            ['Magebit_Mcp::tools']
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(self::OWN_RESOURCE, $tester->getDisplay());
    }

    public function testReportsAnUndeclaredUnderlyingResource(): void
    {
        // tools/list hides a tool whose underlying resource cannot resolve, so a
        // typo here is invisible from the client side — name it at build time.
        $tester = $this->runCommand(
            [$this->makeUnderlyingAclTool(self::OWN_RESOURCE, 'Magento_InventoryApi::typo')],
            [self::OWN_RESOURCE]
        );

        self::assertStringContainsString('Magento_InventoryApi::typo', $tester->getDisplay());
    }

    public function testUndeclaredUnderlyingResourceDoesNotFailTheCommand(): void
    {
        // Underlying resources belong to other modules, which may legitimately be
        // absent on a trimmed install — a warning, not a build break.
        $tester = $this->runCommand(
            [$this->makeUnderlyingAclTool(self::OWN_RESOURCE, 'Magento_InventoryApi::typo')],
            [self::OWN_RESOURCE]
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testNullUnderlyingResourceIsNotReported(): void
    {
        $tester = $this->runCommand(
            [$this->makeUnderlyingAclTool(self::OWN_RESOURCE, null)],
            [self::OWN_RESOURCE]
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('inventory.source_item.set', $tester->getDisplay());
    }

    /**
     * @phpstan-param list<ToolInterface> $tools
     * @phpstan-param list<string> $declaredResourceIds
     */
    private function runCommand(array $tools, array $declaredResourceIds): CommandTester
    {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->getName()] = $tool;
        }
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($byName);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('getAclResources')->willReturn([
            [
                'id' => 'Magento_Backend::admin',
                'children' => array_map(
                    static fn (string $id): array => ['id' => $id],
                    $declaredResourceIds
                ),
            ],
        ]);

        $tester = new CommandTester(new ValidateAclCommand($registry, $provider));
        $tester->execute([]);

        return $tester;
    }

    private function makeTool(string $aclResource): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $this->stubToolBasics($tool, $aclResource);
        return $tool;
    }

    private function makeUnderlyingAclTool(string $aclResource, ?string $underlyingResource): ToolInterface
    {
        $tool = $this->createMock(UnderlyingAclToolFixtureInterface::class);
        $this->stubToolBasics($tool, $aclResource);
        $tool->method('getUnderlyingAclResource')->willReturn($underlyingResource);
        return $tool;
    }

    /**
     * @param ToolInterface&MockObject $tool
     * @param string $aclResource
     * @return void
     */
    private function stubToolBasics($tool, string $aclResource): void
    {
        $tool->method('getName')->willReturn('inventory.source_item.set');
        $tool->method('getAclResource')->willReturn($aclResource);
        $tool->method('getWriteMode')->willReturn(WriteMode::WRITE);
    }
}

/**
 * PHPUnit 9 cannot mock an intersection of types, so the fixture names the combination.
 */
interface UnderlyingAclToolFixtureInterface extends ToolInterface, UnderlyingAclAwareInterface
{
}
